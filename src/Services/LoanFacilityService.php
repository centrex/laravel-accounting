<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Services;

use Centrex\Accounting\Concerns\{HasSharedAccountingHelpers, ManagesJournalEntries};
use Centrex\Accounting\Models\{Account, JournalEntry, LoanFacility};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanFacilityService
{
    use HasSharedAccountingHelpers;
    use ManagesJournalEntries;

    /**
     * Register a loan facility and auto-create its dedicated GL sub-accounts.
     *
     * short_term loans → principal 240x, accrued interest 242x
     * long_term  loans → principal 250x, accrued interest 252x
     *
     * @param  string  $lenderName  Name of the lending entity
     * @param  string  $loanType  term_loan | working_capital | inter_company | director | equipment | overdraft | bridge
     * @param  string  $loanTerm  short_term | long_term
     * @param  float  $monthlyRate  Monthly interest rate (0.02 = 2%)
     * @param  string|null  $sbuCode  SBU all journal entries for this facility will be tagged with
     * @param  float|null  $loanAmount  Sanctioned/approved loan amount (informational)
     * @param  string|null  $disbursedAt  Date the loan was disbursed
     * @param  string|null  $dueAt  Repayment due date
     * @param  int|null  $tenureMonths  Tenure in months
     * @param  string|null  $contact  Lender contact reference
     */
    public function addLoanFacility(
        string $lenderName,
        string $loanType = 'term_loan',
        string $loanTerm = 'short_term',
        float $monthlyRate = 0.02,
        ?string $sbuCode = null,
        ?float $loanAmount = null,
        ?string $disbursedAt = null,
        ?string $dueAt = null,
        ?int $tenureMonths = null,
        ?string $contact = null,
        ?string $currency = null,
        float|int|string|null $exchangeRate = null,
    ): LoanFacility {
        return DB::transaction(function () use (
            $lenderName, $loanType, $loanTerm, $monthlyRate,
            $sbuCode, $loanAmount, $disbursedAt, $dueAt, $tenureMonths, $contact,
            $currency, $exchangeRate,
        ): LoanFacility {
            $isShort = $loanTerm === 'short_term';

            // Ranges: short_term → 2401–2419 / 2421–2439; long_term → 2501–2519 / 2521–2539
            [$principalParentCode, $principalRangeEnd] = $isShort ? ['2400', '2419'] : ['2500', '2519'];
            [$interestParentCode,  $interestRangeEnd] = $isShort ? ['2420', '2439'] : ['2520', '2539'];

            $principalParent = $this->requireAccount($principalParentCode);
            $interestParent = $this->requireAccount($interestParentCode);

            $principalCode = $this->nextSubAccountCode($principalParentCode, $principalRangeEnd);
            $interestCode = $this->nextSubAccountCode($interestParentCode, $interestRangeEnd);

            $shortName = Str::limit($lenderName, 28, '');
            $typeLabel = str_replace('_', ' ', ucfirst($loanType));

            $principalAccount = Account::create([
                'code'      => $principalCode,
                'name'      => "{$typeLabel} Payable — {$shortName}",
                'type'      => 'liability',
                'subtype'   => $isShort ? 'current_liability' : 'long_term_liability',
                'parent_id' => $principalParent->id,
                'is_system' => false,
            ]);

            $interestAccount = Account::create([
                'code'      => $interestCode,
                'name'      => "Accrued Interest — {$shortName}",
                'type'      => 'liability',
                'subtype'   => $isShort ? 'current_liability' : 'long_term_liability',
                'parent_id' => $interestParent->id,
                'is_system' => false,
            ]);

            return LoanFacility::create([
                'lender_name'          => $lenderName,
                'loan_type'            => $loanType,
                'loan_term'            => $loanTerm,
                'lender_contact'       => $contact,
                'sbu_code'             => $sbuCode ? strtoupper(trim($sbuCode)) : null,
                'principal_account_id' => $principalAccount->id,
                'interest_account_id'  => $interestAccount->id,
                'monthly_rate'         => $monthlyRate,
                'loan_amount'          => $loanAmount,
                'currency'             => $currency ? strtoupper(trim($currency)) : $this->baseCurrency(),
                'exchange_rate'        => $this->normalizeExchangeRate($exchangeRate),
                'disbursed_at'         => $disbursedAt,
                'due_at'               => $dueAt,
                'tenure_months'        => $tenureMonths,
                // Explicit rather than relying on the DB column default — Eloquent doesn't
                // refetch after insert, so an omitted key here leaves the returned model's
                // is_active null (falsy) until something calls ->fresh(), which trips
                // drawdownLoan()'s/repayLoan()'s "is inactive" guard on the very facility that
                // was just created.
                'is_active' => true,
            ]);
        });
    }

    /**
     * Record a loan disbursement — funds received into Bank.
     *
     * $amount is in the facility's own currency; createJournalEntry() converts it to the
     * accounting base currency for posting using the facility's exchange_rate.
     *
     * DR fund account (any active asset account 10xx/11xx; defaults to Bank 1100) / CR Loan
     * Payable (240x or 250x). Journal entry is tagged with the facility's sbu_code.
     */
    public function drawdownLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
        ?string $accountCode = null,
    ): JournalEntry {
        if (!$facility->is_active) {
            throw new \RuntimeException("Loan facility '{$facility->lender_name}' is inactive.");
        }

        $bank = $this->requireAccount($accountCode ?? $this->accountCode('bank'));
        $effectiveSbu = $sbuCode ?? $facility->sbu_code;

        return $this->createJournalEntry([
            'date'          => $date,
            'reference'     => $reference,
            'type'          => 'general',
            'description'   => $description ?? "Loan disbursement — {$facility->lender_name}",
            'sbu_code'      => $effectiveSbu,
            'currency'      => $facility->currency,
            'exchange_rate' => $facility->exchange_rate,
            'lines'         => [
                ['account_id' => $bank->id,                          'type' => 'debit',  'amount' => $amount],
                ['account_id' => $facility->principal_account_id,    'type' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    /**
     * Accrue one month's interest for a single loan facility.
     *
     * Interest is calculated on the outstanding principal in the facility's own currency
     * (what the lender actually charges against), then converted to the accounting base
     * currency for the journal entry via createJournalEntry()'s currency/exchange_rate handling.
     *
     * DR Interest Expense 6720 (short) or 6730 (long) / CR Accrued Interest (242x or 252x)
     * Returns null when outstanding principal is zero.
     */
    public function accrueLoanInterest(
        LoanFacility $facility,
        mixed $date = null,
    ): ?JournalEntry {
        return DB::transaction(function () use ($facility, $date): ?JournalEntry {
            // Locked for the duration — see the matching comment in payLoanInterest().
            $facility = LoanFacility::lockForUpdate()->findOrFail($facility->id);

            $principalLocal = $facility->outstandingPrincipalLocal();

            if ($principalLocal <= 0) {
                return null;
            }

            $reference = 'LOAN-INT-' . now()->format('Y-m') . '-' . $facility->id;

            // Idempotency guard — this reference already uniquely identifies "this facility,
            // this month"; a double-fired scheduler run (or an admin retrying after a slow
            // response) would otherwise post a second month's interest for one period.
            if (JournalEntry::where('reference', $reference)->exists()) {
                return null;
            }

            $interestLocal = round($principalLocal * $facility->monthly_rate, 2);
            $date ??= now()->endOfMonth()->toDateString();
            $expenseCode = $facility->isShortTerm() ? '6720' : '6730';
            $expenseAcct = $this->requireAccount($expenseCode);

            return $this->createJournalEntry([
                'date'          => $date,
                'reference'     => $reference,
                'type'          => 'general',
                'sbu_code'      => $facility->sbu_code,
                'currency'      => $facility->currency,
                'exchange_rate' => $facility->exchange_rate,
                'description'   => sprintf(
                    'Loan interest accrual — %s (%s) — %s — principal %s %s × %.2f%%/mo',
                    $facility->lender_name,
                    str_replace('_', ' ', $facility->loan_type),
                    now()->format('F Y'),
                    $facility->currency,
                    number_format($principalLocal, 2),
                    $facility->monthly_rate * 100,
                ),
                'lines' => [
                    ['account_id' => $expenseAcct->id,               'type' => 'debit',  'amount' => $interestLocal],
                    ['account_id' => $facility->interest_account_id, 'type' => 'credit', 'amount' => $interestLocal],
                ],
            ]);
        });
    }

    /**
     * Accrue monthly interest for ALL active loan facilities.
     * Returns array keyed by facility id → JournalEntry|null.
     */
    public function accrueAllLoanInterest(mixed $date = null): array
    {
        $results = [];

        LoanFacility::where('is_active', true)->each(function (LoanFacility $facility) use ($date, &$results): void {
            $results[$facility->id] = $this->accrueLoanInterest($facility, $date);
        });

        return $results;
    }

    /**
     * Pay accrued interest to the lender.
     *
     * $amount is in the facility's own currency, converted to base currency for posting.
     *
     * DR Accrued Interest (242x or 252x) / CR fund account (any active asset account 10xx/11xx;
     * defaults to Bank 1100)
     */
    public function payLoanInterest(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $accountCode = null,
    ): JournalEntry {
        return DB::transaction(function () use ($facility, $amount, $date, $reference, $accountCode): JournalEntry {
            // Locked for the duration of the check-then-post — accruedInterestLocal() is
            // computed live from posted journal lines, not a stored balance column, so
            // without the lock two concurrent payments could each read the same accrued
            // figure, both pass the guard below, and jointly overpay past what's accrued.
            $facility = LoanFacility::lockForUpdate()->findOrFail($facility->id);

            $accruedLocal = $facility->accruedInterestLocal();

            if ($amount > $accruedLocal + 0.01) {
                throw new \RuntimeException(
                    "Payment of {$amount} {$facility->currency} exceeds accrued interest of {$accruedLocal} {$facility->currency} for '{$facility->lender_name}'.",
                );
            }

            $bank = $this->requireAccount($accountCode ?? $this->accountCode('bank'));

            return $this->createJournalEntry([
                'date'          => $date,
                'reference'     => $reference,
                'type'          => 'general',
                'sbu_code'      => $facility->sbu_code,
                'currency'      => $facility->currency,
                'exchange_rate' => $facility->exchange_rate,
                'description'   => "Loan interest payment — {$facility->lender_name}",
                'lines'         => [
                    ['account_id' => $facility->interest_account_id, 'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $bank->id,                      'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Repay principal to the lender.
     *
     * $amount is in the facility's own currency, checked against the outstanding principal
     * in that same currency, then converted to base currency for posting.
     *
     * DR Loan Payable (240x or 250x) / CR fund account (any active asset account 10xx/11xx;
     * defaults to Bank 1100)
     */
    public function repayLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
        ?string $accountCode = null,
    ): JournalEntry {
        return DB::transaction(function () use ($facility, $amount, $date, $reference, $description, $sbuCode, $accountCode): JournalEntry {
            // Locked for the duration — see the matching comment in payLoanInterest().
            $facility = LoanFacility::lockForUpdate()->findOrFail($facility->id);

            $outstandingLocal = $facility->outstandingPrincipalLocal();

            if ($amount > $outstandingLocal + 0.01) {
                throw new \RuntimeException(
                    "Repayment of {$amount} {$facility->currency} exceeds outstanding principal of {$outstandingLocal} {$facility->currency} for '{$facility->lender_name}'.",
                );
            }

            $bank = $this->requireAccount($accountCode ?? $this->accountCode('bank'));
            $effectiveSbu = $sbuCode ?? $facility->sbu_code;

            return $this->createJournalEntry([
                'date'          => $date,
                'reference'     => $reference,
                'type'          => 'general',
                'sbu_code'      => $effectiveSbu,
                'currency'      => $facility->currency,
                'exchange_rate' => $facility->exchange_rate,
                'description'   => $description ?? "Loan principal repayment — {$facility->lender_name}",
                'lines'         => [
                    ['account_id' => $facility->principal_account_id, 'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $bank->id,                       'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Portfolio summary of all loan facilities, optionally filtered by SBU.
     * Includes outstanding principal, accrued interest, monthly charge, and months remaining.
     */
    public function getLoanSummary(?string $sbuCode = null): array
    {
        $query = LoanFacility::with(['principalAccount', 'interestAccount'])
            ->orderBy('loan_term')
            ->orderBy('lender_name');

        if ($sbuCode !== null) {
            $query->where('sbu_code', strtoupper(trim($sbuCode)));
        }

        return $query->get()
            ->map(fn (LoanFacility $f): array => [
                'id'                          => $f->id,
                'lender_name'                 => $f->lender_name,
                'loan_type'                   => $f->loan_type,
                'loan_term'                   => $f->loan_term,
                'sbu_code'                    => $f->sbu_code,
                'is_active'                   => $f->is_active,
                'monthly_rate'                => $f->monthly_rate,
                'loan_amount'                 => $f->loan_amount,
                'currency'                    => $f->currency,
                'exchange_rate'               => $f->exchange_rate,
                'disbursed_at'                => $f->disbursed_at?->toDateString(),
                'due_at'                      => $f->due_at?->toDateString(),
                'months_remaining'            => $f->monthsRemaining(),
                'outstanding_principal'       => $f->outstandingPrincipal(),
                'outstanding_principal_local' => $f->outstandingPrincipalLocal(),
                'accrued_interest'            => $f->accruedInterest(),
                'accrued_interest_local'      => $f->accruedInterestLocal(),
                'monthly_interest'            => $f->monthlyInterestAmount(),
                'principal_account'           => $f->principalAccount?->code . ' ' . $f->principalAccount?->name,
                'interest_account'            => $f->interestAccount?->code . ' ' . $f->interestAccount?->name,
            ])
            ->all();
    }
}
