<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Services;

use Centrex\Accounting\Concerns\{HasSharedAccountingHelpers, ManagesJournalEntries};
use Centrex\Accounting\Models\{Account, InventoryFinancingFacility, JournalEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryFinancingService
{
    use HasSharedAccountingHelpers;
    use ManagesJournalEntries;

    /**
     * Register a new lender and auto-create its dedicated GL sub-accounts.
     *
     * Sub-accounts are allocated sequentially under parent 2150 (principal)
     * and 2170 (accrued interest). Supports up to 19 lenders per range.
     *
     * @param  string  $lenderName  Display name of the financing entity
     * @param  string  $lenderType  bank | private | ngo | mfi | other
     * @param  float  $monthlyRate  Interest rate per month (0.02 = 2%)
     * @param  float|null  $creditLimit  Maximum draw-down allowed (informational)
     * @param  string|null  $contact  Contact person / reference
     */
    public function addFinancingFacility(
        string $lenderName,
        string $lenderType = 'bank',
        float $monthlyRate = 0.02,
        ?float $creditLimit = null,
        ?string $contact = null,
    ): InventoryFinancingFacility {
        return DB::transaction(function () use ($lenderName, $lenderType, $monthlyRate, $creditLimit, $contact): InventoryFinancingFacility {
            $principalParent = $this->requireAccount('2150');
            $interestParent = $this->requireAccount('2170');

            // Next available code under each parent range
            $principalCode = $this->nextSubAccountCode('2150', '2169');
            $interestCode = $this->nextSubAccountCode('2170', '2189');

            $shortName = Str::limit($lenderName, 30, '');

            $principalAccount = Account::create([
                'code'      => $principalCode,
                'name'      => "Inv. Financing Payable — {$shortName}",
                'type'      => 'liability',
                'subtype'   => 'current_liability',
                'parent_id' => $principalParent->id,
                'is_system' => false,
            ]);

            $interestAccount = Account::create([
                'code'      => $interestCode,
                'name'      => "Accrued Interest — {$shortName}",
                'type'      => 'liability',
                'subtype'   => 'current_liability',
                'parent_id' => $interestParent->id,
                'is_system' => false,
            ]);

            return InventoryFinancingFacility::create([
                'lender_name'          => $lenderName,
                'lender_type'          => $lenderType,
                'lender_contact'       => $contact,
                'principal_account_id' => $principalAccount->id,
                'interest_account_id'  => $interestAccount->id,
                'monthly_rate'         => $monthlyRate,
                'credit_limit'         => $creditLimit,
            ]);
        });
    }

    /**
     * Draw down funds from a financing facility to purchase inventory.
     *
     * DR Inventory (1300) / CR Facility Principal Payable (215x)
     */
    public function drawdownFinancing(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
    ): JournalEntry {
        return DB::transaction(function () use ($facility, $amount, $date, $reference, $description): JournalEntry {
            // Locked for the duration of the check-then-post — outstandingPrincipal() is
            // computed live from posted journal lines, not a stored balance column, so
            // without the lock two concurrent draw-downs could each read the same
            // outstanding figure and jointly exceed the credit limit.
            $facility = InventoryFinancingFacility::lockForUpdate()->findOrFail($facility->id);

            if (!$facility->is_active) {
                throw new \RuntimeException("Financing facility '{$facility->lender_name}' is inactive.");
            }

            $creditLimit = $facility->credit_limit;

            if ($creditLimit !== null) {
                $outstanding = $facility->outstandingPrincipal();

                if (($outstanding + $amount) > $creditLimit) {
                    throw new \RuntimeException(
                        "Draw-down of {$amount} would exceed credit limit of {$creditLimit} for '{$facility->lender_name}'.",
                    );
                }
            }

            $inventory = $this->requireAccount($this->accountCode('inventory'));

            return $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference,
                'type'        => 'general',
                'description' => $description ?? "Inventory financing draw-down — {$facility->lender_name}",
                'lines'       => [
                    ['account_id' => $inventory->id,                     'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $facility->principal_account_id,    'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Accrue one month's interest for a single facility.
     *
     * DR Interest Expense — Inv. Financing (6710) / CR Accrued Interest (217x)
     * Skipped (returns null) when outstanding principal is zero.
     */
    public function accrueFinancingInterest(
        InventoryFinancingFacility $facility,
        mixed $date = null,
    ): ?JournalEntry {
        return DB::transaction(function () use ($facility, $date): ?JournalEntry {
            // Locked for the duration — see the matching comment in drawdownFinancing().
            $facility = InventoryFinancingFacility::lockForUpdate()->findOrFail($facility->id);

            $principal = $facility->outstandingPrincipal();

            if ($principal <= 0) {
                return null;
            }

            $reference = 'INT-' . now()->format('Y-m') . '-' . $facility->id;

            // Idempotency guard — this reference already uniquely identifies "this
            // facility, this month"; a double-fired scheduler run would otherwise post a
            // second month's interest for one period. See the matching guard in
            // LoanFacilityService::accrueLoanInterest().
            if (JournalEntry::where('reference', $reference)->exists()) {
                return null;
            }

            $interest = round($principal * $facility->monthly_rate, 2);
            $date ??= now()->endOfMonth()->toDateString();
            $interestAcct = $this->requireAccount($this->accountCode('financing_interest'));

            return $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference,
                'type'        => 'general',
                'description' => sprintf(
                    'Interest accrual — %s — %s — principal %s × %.2f%%/mo',
                    $facility->lender_name,
                    now()->format('F Y'),
                    number_format($principal, 2),
                    $facility->monthly_rate * 100,
                ),
                'lines' => [
                    ['account_id' => $interestAcct->id,               'type' => 'debit',  'amount' => $interest],
                    ['account_id' => $facility->interest_account_id,  'type' => 'credit', 'amount' => $interest],
                ],
            ]);
        });
    }

    /**
     * Accrue monthly interest for ALL active facilities in a single call.
     * Returns an array keyed by facility id → JournalEntry|null.
     */
    public function accrueAllFinancingInterest(mixed $date = null): array
    {
        $results = [];

        InventoryFinancingFacility::where('is_active', true)->each(function (InventoryFinancingFacility $facility) use ($date, &$results): void {
            $results[$facility->id] = $this->accrueFinancingInterest($facility, $date);
        });

        return $results;
    }

    /**
     * Pay accrued interest for a facility.
     *
     * DR Accrued Interest (217x) / CR Bank (1100)
     */
    public function payFinancingInterest(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
    ): JournalEntry {
        return DB::transaction(function () use ($facility, $amount, $date, $reference): JournalEntry {
            // Locked for the duration — see the matching comment in drawdownFinancing().
            $facility = InventoryFinancingFacility::lockForUpdate()->findOrFail($facility->id);

            $accrued = $facility->accruedInterest();

            if ($amount > $accrued + 0.01) {
                throw new \RuntimeException(
                    "Payment of {$amount} exceeds accrued interest of {$accrued} for '{$facility->lender_name}'.",
                );
            }

            $bank = $this->requireAccount($this->accountCode('bank'));

            return $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference,
                'type'        => 'general',
                'description' => "Interest payment — {$facility->lender_name}",
                'lines'       => [
                    ['account_id' => $facility->interest_account_id, 'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $bank->id,                      'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Repay principal to a financing lender (typically as inventory is sold).
     *
     * DR Facility Principal Payable (215x) / CR Bank (1100)
     */
    public function repayFinancing(
        InventoryFinancingFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
    ): JournalEntry {
        return DB::transaction(function () use ($facility, $amount, $date, $reference, $description): JournalEntry {
            // Locked for the duration — see the matching comment in drawdownFinancing().
            $facility = InventoryFinancingFacility::lockForUpdate()->findOrFail($facility->id);

            $outstanding = $facility->outstandingPrincipal();

            if ($amount > $outstanding + 0.01) {
                throw new \RuntimeException(
                    "Repayment of {$amount} exceeds outstanding principal of {$outstanding} for '{$facility->lender_name}'.",
                );
            }

            $bank = $this->requireAccount($this->accountCode('bank'));

            return $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference,
                'type'        => 'general',
                'description' => $description ?? "Principal repayment — {$facility->lender_name}",
                'lines'       => [
                    ['account_id' => $facility->principal_account_id, 'type' => 'debit',  'amount' => $amount],
                    ['account_id' => $bank->id,                       'type' => 'credit', 'amount' => $amount],
                ],
            ]);
        });
    }

    /**
     * Summary of all financing facilities with current balances.
     */
    public function getFinancingSummary(): array
    {
        return InventoryFinancingFacility::with(['principalAccount', 'interestAccount'])
            ->orderBy('lender_name')
            ->get()
            ->map(fn (InventoryFinancingFacility $f): array => [
                'id'                    => $f->id,
                'lender_name'           => $f->lender_name,
                'lender_type'           => $f->lender_type,
                'is_active'             => $f->is_active,
                'monthly_rate'          => $f->monthly_rate,
                'credit_limit'          => $f->credit_limit,
                'outstanding_principal' => $f->outstandingPrincipal(),
                'accrued_interest'      => $f->accruedInterest(),
                'monthly_interest'      => $f->monthlyInterestAmount(),
                'principal_account'     => $f->principalAccount?->code . ' ' . $f->principalAccount?->name,
                'interest_account'      => $f->interestAccount?->code . ' ' . $f->interestAccount?->name,
            ])
            ->all();
    }
}
