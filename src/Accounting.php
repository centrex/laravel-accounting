<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Carbon\Carbon;
use Centrex\Accounting\Contracts\InventorySnapshotProvider;
use Centrex\Accounting\Enums\{BankReconciliationStatus, RequisitionStatus, RequisitionType};
use Centrex\Accounting\Exceptions\{
    AccountNotFoundException,
    AccountingException,
    AmountToleranceExceededException,
    DuplicatePaymentException,
    InvalidStatusTransitionException,
    OverpaymentException,
    ReconciliationBalanceMismatchException,
    StatementLineAlreadyMatchedException,
    StatementLinePolarityMismatchException,
    UnbalancedJournalException
};
use Centrex\Accounting\Models\{
    Account,
    AccountBalance,
    BankReconciliation,
    BankStatementLine,
    Bill,
    BillItem,
    Budget,
    BudgetItem,
    CreditMemo,
    FiscalPeriod,
    FiscalYear,
    FixedAsset,
    InventoryFinancingFacility,
    Invoice,
    InvoiceItem,
    JournalEntry,
    JournalEntryLine,
    LoanFacility,
    Payment,
    PeriodInventorySnapshot,
    Requisition,
    RequisitionItem,
    TaxRate
};
use Centrex\Accounting\Models\Expense;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Gate};

class Accounting
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Rounding tolerance used consistently across all balance checks. */
    private function tolerance(): float
    {
        return (float) config('accounting.rounding_tolerance', 0.005);
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) config('accounting.base_currency', 'BDT'));
    }

    private function normalizeExchangeRate(float|int|string|null $exchangeRate): float
    {
        $rate = (float) ($exchangeRate ?? 1);

        return $rate > 0 ? $rate : 1.0;
    }

    private function convertToBaseAmount(float|int|string|null $amount, ?string $currency = null, float|int|string|null $exchangeRate = null): float
    {
        $value = (float) ($amount ?? 0);
        $sourceCurrency = strtoupper((string) ($currency ?: $this->baseCurrency()));

        if ($sourceCurrency === $this->baseCurrency()) {
            return round($value, 2);
        }

        return round($value * $this->normalizeExchangeRate($exchangeRate), 2);
    }

    private function normalizeSbuCode(?string $sbuCode): ?string
    {
        $value = strtoupper(trim((string) $sbuCode));

        return $value !== '' ? $value : null;
    }

    private function applySbuFilter(mixed $query, ?string $sbuCode, string $journalEntryAlias = 'je'): mixed
    {
        $sbuCode = $this->normalizeSbuCode($sbuCode);

        if ($sbuCode === null) {
            return $query;
        }

        return $query->where("{$journalEntryAlias}.sbu_code", $sbuCode);
    }

    private function extractSbuCodeFromMeta(mixed $meta): ?string
    {
        if (!is_array($meta)) {
            return null;
        }

        return $this->normalizeSbuCode(
            $meta['default_sbu'] ?? $meta['sbu_code'] ?? $meta['sbu'] ?? null,
        );
    }

    private function resolveModelSbuCode(mixed $model): ?string
    {
        if (!is_object($model)) {
            return null;
        }

        // Use getAttributes() to guard against MissingAttributeException on models
        // (e.g. App\Models\User) that don't have a meta column.
        if (method_exists($model, 'getAttributes')) {
            $meta = array_key_exists('meta', $model->getAttributes())
                ? $model->getAttribute('meta')
                : null;
        } else {
            $meta = $model->meta ?? null;
        }

        return $this->extractSbuCodeFromMeta($meta);
    }

    /** Resolve a semantic account key to a code from config, with a hardcoded fallback. */
    private function accountCode(string $key): string
    {
        return (string) config("accounting.accounts.{$key}");
    }

    private function resolveInvoiceSbuCode(Invoice $invoice): ?string
    {
        $existingEntrySbu = $this->normalizeSbuCode($invoice->journalEntry?->sbu_code);

        if ($existingEntrySbu !== null) {
            return $existingEntrySbu;
        }

        $documentSbu = $this->normalizeSbuCode($invoice->sbu_code);

        if ($documentSbu !== null) {
            return $documentSbu;
        }

        $customer = $invoice->relationLoaded('customer') ? $invoice->customer : $invoice->customer()->with('modelable')->first();

        return $this->resolveModelSbuCode($customer?->modelable) ?? $this->resolveModelSbuCode($customer);
    }

    private function resolveBillSbuCode(Bill $bill): ?string
    {
        $existingEntrySbu = $this->normalizeSbuCode($bill->journalEntry?->sbu_code);

        if ($existingEntrySbu !== null) {
            return $existingEntrySbu;
        }

        $documentSbu = $this->normalizeSbuCode($bill->sbu_code);

        if ($documentSbu !== null) {
            return $documentSbu;
        }

        $vendor = $bill->relationLoaded('vendor') ? $bill->vendor : $bill->vendor()->with('modelable')->first();

        return $this->resolveModelSbuCode($vendor?->modelable) ?? $this->resolveModelSbuCode($vendor);
    }

    private function resolveExpenseSbuCode(Expense $expense, ?array $paymentData = null): ?string
    {
        $paymentSbu = $this->normalizeSbuCode($paymentData['sbu_code'] ?? null);

        if ($paymentSbu !== null) {
            return $paymentSbu;
        }

        return $this->normalizeSbuCode($expense->journalEntry?->sbu_code);
    }

    /**
     * Normalize a journal payload into base currency before persistence.
     *
     * @param  array{currency?: string, exchange_rate?: float, lines?: array<int, array{amount?: float|int|string}>}  $data
     * @return array<string, mixed>
     */
    private function normalizeJournalPayload(array $data): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? $this->baseCurrency()));
        $exchangeRate = $this->normalizeExchangeRate($data['exchange_rate'] ?? 1);

        $data['currency'] = $this->baseCurrency();
        $data['exchange_rate'] = 1.0;
        $data['lines'] = collect($data['lines'] ?? [])
            ->map(function (array $line) use ($currency, $exchangeRate): array {
                $line['amount'] = $this->convertToBaseAmount($line['amount'] ?? 0, $currency, $exchangeRate);

                return $line;
            })
            ->all();

        return $data;
    }

    /** Resolve a required account by code or throw a typed exception. */
    private function requireAccount(string $code): Account
    {
        return Account::where('code', $code)->where('is_active', true)->first()
            ?? throw AccountNotFoundException::forCode($code);
    }

    /**
     * Find the next unused account code within [rangeStart, rangeEnd].
     * Used to allocate per-lender sub-accounts sequentially.
     */
    private function nextSubAccountCode(string $rangeStart, string $rangeEnd): string
    {
        $existing = Account::whereBetween('code', [$rangeStart, $rangeEnd])
            ->pluck('code')
            ->map(fn ($c): int => (int) $c)
            ->sort()
            ->values();

        $start = (int) $rangeStart;
        $end = (int) $rangeEnd;

        for ($code = $start + 1; $code <= $end; $code++) {
            if (!$existing->contains($code)) {
                return (string) $code;
            }
        }

        throw new \RuntimeException(
            "No available account codes between {$rangeStart} and {$rangeEnd}. Add more range capacity.",
        );
    }

    /**
     * Single aggregated query for journal-entry-line balances.
     * Replaces the N+1 pattern (3 queries per account) in every report method.
     *
     * Returns a Collection keyed by account_id, each item having
     * `total_debit` and `total_credit` properties.
     */
    private function buildBalanceMap(mixed $startDate, mixed $endDate, ?string $sbuCode = null): Collection
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        $query = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->when($startDate, fn ($q) => $q->whereDate('je.date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('je.date', '<=', $endDate))
            ->select([
                'l.account_id',
                DB::raw("SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END) as total_debit"),
                DB::raw("SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit"),
            ])
            ->groupBy('l.account_id');

        return $this->applySbuFilter($query, $sbuCode)->get()->keyBy('account_id');
    }

    // -------------------------------------------------------------------------
    // Journal Entries
    // -------------------------------------------------------------------------

    /**
     * Create a balanced journal entry with lines.
     *
     * @param  array{date: string, reference?: string, type?: string, description?: string,
     *               currency?: string, exchange_rate?: float, sbu_code?: string, lines: list<array{account_id: int,
     *               type: string, amount: float, description?: string, reference?: string}>} $data
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        $data = $this->normalizeJournalPayload($data);
        $data['sbu_code'] = $this->normalizeSbuCode($data['sbu_code'] ?? null);
        $usesGeneratedEntryNumber = empty($data['entry_number']);

        return DB::transaction(function () use ($data, $usesGeneratedEntryNumber): JournalEntry {
            $attempts = $usesGeneratedEntryNumber ? 5 : 1;
            $lastException = null;

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                try {
                    $entry = JournalEntry::create([
                        'entry_number'  => $data['entry_number'] ?? $this->generateJournalEntryNumber(),
                        'date'          => $data['date'],
                        'reference'     => $data['reference'] ?? null,
                        'type'          => $data['type'] ?? 'general',
                        'description'   => $data['description'] ?? null,
                        'currency'      => $data['currency'] ?? config('accounting.base_currency', 'BDT'),
                        'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                        'created_by'    => $data['created_by'] ?? auth()->id(),
                        'status'        => $data['status'] ?? 'draft',
                        'source_type'   => $data['source_type'] ?? null,
                        'source_id'     => $data['source_id'] ?? null,
                        'source_action' => $data['source_action'] ?? null,
                        'sbu_code'      => $data['sbu_code'] ?? null,
                    ]);

                    foreach ($data['lines'] as $line) {
                        $entry->lines()->create([
                            'account_id'  => $line['account_id'],
                            'type'        => strtolower((string) $line['type']),
                            'amount'      => $line['amount'],
                            'description' => $line['description'] ?? null,
                            'reference'   => $line['reference'] ?? null,
                        ]);
                    }

                    if (!$entry->isBalanced()) {
                        throw UnbalancedJournalException::make($entry);
                    }

                    return $entry;
                } catch (QueryException $exception) {
                    if (!$usesGeneratedEntryNumber || !$this->isDuplicateJournalEntryNumberException($exception)) {
                        throw $exception;
                    }

                    $lastException = $exception;
                }
            }

            throw $lastException ?? new \RuntimeException('Unable to generate a unique journal entry number.');
        });
    }

    protected function generateJournalEntryNumber(): string
    {
        return 'JE-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(8));
    }

    protected function isDuplicateJournalEntryNumberException(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        // MySQL reports code 1062 with the named unique index in the message;
        // SQLite reports code 19 with a plain "table.column" constraint message.
        $isDuplicateKeyError = in_array($driverCode, [1062, 19], true);

        return $isDuplicateKeyError && str_contains($message, 'entry_number');
    }

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------

    /** Post an invoice: create & post a journal entry, update invoice status to 'issued'. */
    public function postInvoice(Invoice $invoice): JournalEntry
    {
        if ($invoice->status === Enums\EntryStatus::SETTLED) {
            throw InvalidStatusTransitionException::make('Invoice', 'settled', 'posted');
        }

        if ($invoice->journal_entry_id !== null) {
            throw InvalidStatusTransitionException::make('Invoice', $invoice->status->value, 'posted');
        }

        return DB::transaction(function () use ($invoice): JournalEntry {
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));
            $revenueAccount = $this->requireAccount($this->accountCode('sales_revenue'));
            $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
            $discountAmount = round((float) ($invoice->discount_amount ?? 0), 2);
            $shippingAmount = round((float) ($invoice->shipping_amount ?? 0), 2);
            // Shipping billed to the customer is folded into revenue — invoice->total
            // (AR debit) already includes it, so revenue must too for the entry to balance.
            $netRevenueAmount = round((float) $invoice->subtotal + $shippingAmount - $discountAmount, 2);

            $entry = $this->createJournalEntry([
                'date'          => $invoice->invoice_date,
                'reference'     => $invoice->invoice_number,
                'type'          => 'general',
                'description'   => "Invoice {$invoice->invoice_number} - {$invoice->customer?->name}",
                'currency'      => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $invoice->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveInvoiceSbuCode($invoice),
                'lines'         => [
                    ['account_id' => $arAccount->id,      'type' => 'debit',  'amount' => $invoice->total,      'description' => 'Accounts Receivable'],
                    ['account_id' => $revenueAccount->id, 'type' => 'credit', 'amount' => $netRevenueAmount, 'description' => $discountAmount > 0 ? 'Sales Revenue (net of discount)' : 'Sales Revenue'],
                    ['account_id' => $taxAccount->id,     'type' => 'credit', 'amount' => $invoice->tax_amount, 'description' => 'Sales Tax'],
                ],
            ]);

            $entry->post();

            $invoice->update(['journal_entry_id' => $entry->id, 'status' => 'issued']);

            return $entry;
        });
    }

    /**
     * Record a payment against an invoice.
     *
     * Uses a pessimistic row-lock to prevent concurrent payment races.
     * Validates overpayment and idempotency before writing anything.
     */
    public function recordInvoicePayment(Invoice $invoice, array $paymentData): Payment
    {
        return DB::transaction(function () use ($invoice, $paymentData): Payment {
            // Pessimistic lock — blocks concurrent payments for the same invoice
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            $amount = (float) $paymentData['amount'];
            // Optional shipping/handling charge netted off AR alongside the cash received —
            // reduces the customer's balance without an extra cash leg.
            $chargeAmount = (float) ($paymentData['charge_amount'] ?? 0);
            $arReduction = round($amount + $chargeAmount, 6);
            $outstanding = round((float) $invoice->total - (float) $invoice->paid_amount, 6);

            if ($arReduction > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($arReduction, $outstanding);
            }

            // Idempotency guard — same amount + date + method = duplicate
            if (
                Payment::where('payable_type', Invoice::class)
                    ->where('payable_id', $invoice->id)
                    ->where('amount', $amount)
                    ->whereDate('payment_date', $paymentData['date'])
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forInvoice($invoice->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Invoice::class,
                'payable_id'     => $invoice->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            // Resolve cash account — caller may specify a custom account code (e.g. bank vs cash)
            $cashCode = $paymentData['account_code'] ?? $this->accountCode('cash');
            $cashAccount = $this->requireAccount($cashCode);
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $lines = [
                ['account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => $amount, 'description' => 'Cash received'],
            ];

            $chargeAccount = null;

            if ($chargeAmount > 0) {
                $chargeAccount = $this->requireAccount((string) ($paymentData['charge_account_code'] ?? $this->accountCode('shipping')));
                $lines[] = ['account_id' => $chargeAccount->id, 'type' => 'debit', 'amount' => $chargeAmount, 'description' => 'Shipping/handling charge netted off AR'];
            }

            $lines[] = ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => $arReduction, 'description' => 'Accounts Receivable'];

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
                'currency'    => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->resolveInvoiceSbuCode($invoice),
                'lines'       => $lines,
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            if ($chargeAccount !== null) {
                // No journal_entry_id here — this charge is already booked as a line inside
                // $entry above. Linking the same entry a second time would make it show up
                // twice in views that merge invoice->payments and invoice->expenses journal
                // entries (e.g. the invoice audit trail), making one payment look like two.
                Expense::create([
                    'chargeable_type' => Invoice::class,
                    'chargeable_id'   => $invoice->id,
                    'account_id'      => $chargeAccount->id,
                    'expense_date'    => $paymentData['date'],
                    'subtotal'        => $chargeAmount,
                    'tax_amount'      => 0,
                    'total'           => $chargeAmount,
                    'paid_amount'     => $chargeAmount,
                    'currency'        => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                    'status'          => 'paid',
                    'payment_method'  => 'ar_deduction',
                    'reference'       => $payment->payment_number,
                    'notes'           => 'Netted off AR during payment ' . $payment->payment_number,
                ]);
            }

            // Atomic status update — compute from known locked value, no refresh needed
            $newPaid = round((float) $invoice->paid_amount + $arReduction, 6);
            $newStatus = $newPaid >= (float) $invoice->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $invoice->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });
    }

    // -------------------------------------------------------------------------
    // Credit memos
    // -------------------------------------------------------------------------

    /**
     * Create a draft credit memo against an invoice (e.g. for a sale return).
     * No accounting impact until issueCreditMemo() is called.
     *
     * @param array{
     *     date?: mixed, reason?: string, subtotal?: float|int|string, tax_amount?: float|int|string,
     *     total?: float|int|string, source_type?: string, source_id?: int, source_reference?: string,
     *     sbu_code?: string, notes?: string, created_by?: int
     * } $data
     */
    public function createCreditMemo(Invoice $invoice, array $data): CreditMemo
    {
        if ($invoice->journal_entry_id === null) {
            throw new AccountingException("Invoice {$invoice->invoice_number} must be posted before a credit memo can be raised against it.");
        }

        $subtotal = round((float) ($data['subtotal'] ?? 0), 2);
        $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
        $total = round((float) ($data['total'] ?? $subtotal + $taxAmount), 2);

        if ($total <= 0) {
            throw new AccountingException('Credit memo total must be greater than zero.');
        }

        return CreditMemo::create([
            'invoice_id'       => $invoice->id,
            'customer_id'      => $invoice->customer_id,
            'credit_memo_date' => $data['date'] ?? now()->toDateString(),
            'reason'           => $data['reason'] ?? null,
            'currency'         => $invoice->currency ?? $this->baseCurrency(),
            'exchange_rate'    => $invoice->exchange_rate ?? 1.0,
            'subtotal'         => $subtotal,
            'tax_amount'       => $taxAmount,
            'total'            => $total,
            'status'           => Enums\CreditMemoStatus::DRAFT->value,
            'source_type'      => $data['source_type'] ?? null,
            'source_id'        => $data['source_id'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'sbu_code'         => $this->normalizeSbuCode($data['sbu_code'] ?? null) ?? $this->resolveInvoiceSbuCode($invoice),
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Issue a draft credit memo: posts the reversing journal entry
     * DR Sales Returns (6134) + DR Tax Payable / CR Accounts Receivable.
     *
     * Guard: across all issued memos and manual discounts, an invoice can never be
     * credited for more than it was invoiced (payments are irrelevant here — a fully
     * paid invoice can still be credited, driving its balance negative, which is what
     * flags a cash refund as owed).
     */
    public function issueCreditMemo(CreditMemo $creditMemo): JournalEntry
    {
        return DB::transaction(function () use ($creditMemo): JournalEntry {
            $creditMemo = CreditMemo::lockForUpdate()->findOrFail($creditMemo->id);

            if ($creditMemo->status !== Enums\CreditMemoStatus::DRAFT) {
                throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'issued');
            }

            $invoice = Invoice::lockForUpdate()->findOrFail($creditMemo->invoice_id);

            $alreadyCredited = (float) $invoice->creditMemos()
                ->whereNotIn('status', [Enums\CreditMemoStatus::DRAFT->value, Enums\CreditMemoStatus::VOID->value])
                ->sum('total');
            $discounts = (float) $invoice->expenses()
                ->whereHas('account', fn ($q) => $q->whereIn('code', Invoice::AR_REDUCING_ACCOUNT_CODES))
                ->sum('total');
            $creditable = round((float) $invoice->total - $alreadyCredited - $discounts, 2);

            if ((float) $creditMemo->total > $creditable + $this->tolerance()) {
                throw new AccountingException(sprintf(
                    'Credit memo total %.2f exceeds the creditable balance %.2f of invoice %s.',
                    (float) $creditMemo->total,
                    $creditable,
                    $invoice->invoice_number,
                ));
            }

            $salesReturnsAccount = $this->requireAccount($this->accountCode('sales_returns'));
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $lines = [
                ['account_id' => $salesReturnsAccount->id, 'type' => 'debit', 'amount' => $creditMemo->subtotal, 'description' => 'Sales Returns & Allowances'],
            ];

            if ((float) $creditMemo->tax_amount > 0) {
                $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
                $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => $creditMemo->tax_amount, 'description' => 'Sales Tax reversal'];
            }

            $lines[] = ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => $creditMemo->total, 'description' => 'Accounts Receivable'];

            $entry = $this->createJournalEntry([
                'date'          => $creditMemo->credit_memo_date,
                'reference'     => $creditMemo->credit_memo_number,
                'type'          => 'general',
                'description'   => "Credit memo {$creditMemo->credit_memo_number} against Invoice {$invoice->invoice_number}",
                'currency'      => $creditMemo->currency ?? $this->baseCurrency(),
                'exchange_rate' => $creditMemo->exchange_rate ?? 1.0,
                'sbu_code'      => $this->normalizeSbuCode($creditMemo->sbu_code) ?? $this->resolveInvoiceSbuCode($invoice),
                'source_type'   => CreditMemo::class,
                'source_id'     => $creditMemo->id,
                'source_action' => 'credit_memo_issued',
                'lines'         => $lines,
            ]);

            $entry->post();

            $creditMemo->update([
                'journal_entry_id' => $entry->id,
                'status'           => Enums\CreditMemoStatus::ISSUED->value,
                'issued_by'        => auth()->id(),
                'issued_at'        => now(),
            ]);

            return $entry;
        });
    }

    /**
     * Refund an issued credit memo in cash: DR Accounts Receivable / CR Cash (or bank).
     *
     * The issue step already credited AR, so the refund debits it back while cash goes
     * out — netting the customer's AR effect of the memo to zero. Recorded as a Payment
     * row (like invoice payments) for the audit trail.
     *
     * @param  array{date: mixed, amount: float|int|string, method: string, account_code?: string, reference?: string, notes?: string, sbu_code?: string}  $paymentData
     */
    public function recordCreditMemoRefund(CreditMemo $creditMemo, array $paymentData): Payment
    {
        return DB::transaction(function () use ($creditMemo, $paymentData): Payment {
            $creditMemo = CreditMemo::lockForUpdate()->findOrFail($creditMemo->id);

            if (!in_array($creditMemo->status, [Enums\CreditMemoStatus::ISSUED, Enums\CreditMemoStatus::PARTIALLY_REFUNDED], true)) {
                throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'refunded');
            }

            $amount = round((float) $paymentData['amount'], 2);
            $refundable = round((float) $creditMemo->total - (float) $creditMemo->amount_refunded, 2);

            if ($amount <= 0) {
                throw new AccountingException('Refund amount must be greater than zero.');
            }

            if ($amount > $refundable + $this->tolerance()) {
                throw OverpaymentException::make($amount, $refundable);
            }

            // Idempotency guard — same amount + date + method = duplicate
            if (
                Payment::where('payable_type', CreditMemo::class)
                    ->where('payable_id', $creditMemo->id)
                    ->where('amount', $amount)
                    ->whereDate('payment_date', $paymentData['date'])
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw new AccountingException("A refund of {$amount} on {$paymentData['date']} already exists for credit memo {$creditMemo->credit_memo_number}.");
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('RFND-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => CreditMemo::class,
                'payable_id'     => $creditMemo->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $cashCode = $paymentData['account_code'] ?? $this->accountCode('cash');
            $cashAccount = $this->requireAccount((string) $cashCode);
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $entry = $this->createJournalEntry([
                'date'          => $paymentData['date'],
                'reference'     => $payment->payment_number,
                'description'   => "Refund for Credit memo {$creditMemo->credit_memo_number}",
                'currency'      => $creditMemo->currency ?? $this->baseCurrency(),
                'sbu_code'      => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->normalizeSbuCode($creditMemo->sbu_code),
                'source_type'   => CreditMemo::class,
                'source_id'     => $creditMemo->id,
                'source_action' => 'credit_memo_refunded',
                'lines'         => [
                    ['account_id' => $arAccount->id,   'type' => 'debit',  'amount' => $amount, 'description' => 'Accounts Receivable'],
                    ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash refunded to customer'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            // Atomic status update — compute from known locked value, no refresh needed
            $newRefunded = round((float) $creditMemo->amount_refunded + $amount, 2);
            $newStatus = $newRefunded >= (float) $creditMemo->total - $this->tolerance()
                ? Enums\CreditMemoStatus::REFUNDED->value
                : Enums\CreditMemoStatus::PARTIALLY_REFUNDED->value;

            $creditMemo->update(['amount_refunded' => $newRefunded, 'status' => $newStatus]);

            return $payment;
        });
    }

    /** Void a draft credit memo. Issued memos cannot be voided — their JE has already posted. */
    public function voidCreditMemo(CreditMemo $creditMemo): CreditMemo
    {
        if ($creditMemo->status !== Enums\CreditMemoStatus::DRAFT) {
            throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'void');
        }

        $creditMemo->update(['status' => Enums\CreditMemoStatus::VOID->value]);

        return $creditMemo;
    }

    // -------------------------------------------------------------------------
    // Bills
    // -------------------------------------------------------------------------

    /** Post a bill: DR Inventory Asset + Tax / CR Accounts Payable. */
    public function postBill(Bill $bill): JournalEntry
    {
        if ($bill->journal_entry_id !== null) {
            throw InvalidStatusTransitionException::make('Bill', $bill->status->value, 'posted');
        }

        return DB::transaction(function () use ($bill): JournalEntry {
            $apAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $expenseAccount = $this->requireAccount($this->accountCode('inventory'));
            $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
            $discountAmount = round((float) ($bill->discount_amount ?? 0), 2);
            $shippingAmount = round((float) ($bill->shipping_amount ?? 0), 2);
            $otherChargesAmount = round((float) ($bill->other_charges_amount ?? 0), 2);
            // Shipping/other charges from the vendor are folded into the inventory cost —
            // bill->total (AP credit) already includes them, so the debit side must too.
            $netExpenseAmount = round((float) $bill->subtotal + $shippingAmount + $otherChargesAmount - $discountAmount, 2);

            // If the goods this bill covers were already capitalized to Inventory via an earlier
            // goods-received posting (e.g. an inventory GRN), don't debit Inventory for that
            // portion again — clear it against the GRNI liability instead. Only the remainder
            // (typically shipping/other charges not known at receipt time, or the full amount if
            // nothing was received yet) hits Inventory here.
            $grniClearAmount = min(max(0.0, (float) ($bill->grni_clearing_amount ?? 0)), $netExpenseAmount);
            $inventoryDebitAmount = round($netExpenseAmount - $grniClearAmount, 2);

            $lines = [];

            if ($inventoryDebitAmount > 0) {
                $lines[] = ['account_id' => $expenseAccount->id, 'type' => 'debit', 'amount' => $inventoryDebitAmount, 'description' => 'Inventory'];
            }

            if ($grniClearAmount > 0) {
                $grniAccount = $this->requireAccount($this->accountCode('goods_received_clearing'));
                $lines[] = ['account_id' => $grniAccount->id, 'type' => 'debit', 'amount' => $grniClearAmount, 'description' => 'Clear goods received not invoiced'];
            }

            $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => $bill->tax_amount, 'description' => 'Tax'];
            $lines[] = ['account_id' => $apAccount->id, 'type' => 'credit', 'amount' => $bill->total, 'description' => 'Accounts Payable'];

            $entry = $this->createJournalEntry([
                'date'          => $bill->bill_date,
                'reference'     => $bill->bill_number,
                'description'   => "Bill {$bill->bill_number} - {$bill->vendor?->name}",
                'currency'      => $bill->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $bill->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveBillSbuCode($bill),
                'lines'         => $lines,
            ]);

            $entry->post();
            $bill->update(['journal_entry_id' => $entry->id, 'status' => 'issued']);

            return $entry;
        });
    }

    /** Record a bill payment: DR Accounts Payable / CR Cash. */
    public function recordBillPayment(Bill $bill, array $paymentData): Payment
    {
        return DB::transaction(function () use ($bill, $paymentData): Payment {
            $bill = Bill::lockForUpdate()->findOrFail($bill->id);

            $amount = (float) $paymentData['amount'];
            $outstanding = round((float) $bill->total - (float) $bill->paid_amount, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
            }

            if (
                Payment::where('payable_type', Bill::class)
                    ->where('payable_id', $bill->id)
                    ->where('amount', $amount)
                    ->whereDate('payment_date', $paymentData['date'])
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forBill($bill->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Bill::class,
                'payable_id'     => $bill->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $apAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? $this->accountCode('cash'));

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Payment for Bill {$bill->bill_number}",
                'currency'    => $bill->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->resolveBillSbuCode($bill),
                'lines'       => [
                    ['account_id' => $apAccount->id,   'type' => 'debit',  'amount' => $amount, 'description' => 'Accounts Payable'],
                    ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash paid'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            $newPaid = round((float) $bill->paid_amount + $amount, 6);
            $newStatus = $newPaid >= (float) $bill->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $bill->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });
    }

    // -------------------------------------------------------------------------
    // Expenses
    // -------------------------------------------------------------------------

    /** Post an inventory-owned expense into the ledger. */
    public function postExpense(Expense $expense): JournalEntry
    {
        if (in_array($expense->status, ['paid', 'settled'], true)) {
            throw InvalidStatusTransitionException::make('Expense', $expense->status, 'posted');
        }

        if ($expense->journal_entry_id !== null) {
            throw InvalidStatusTransitionException::make('Expense', (string) $expense->status, 'posted');
        }

        return DB::transaction(function () use ($expense): JournalEntry {
            $expenseAccount = $expense->account_id
                ? (Account::find($expense->account_id) ?? throw AccountNotFoundException::forCode('custom'))
                : $this->requireAccount($this->accountCode('cogs'));

            $cashAccount = $this->requireAccount($expense->payment_account_code ?? $this->accountCode('cash'));
            $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $taxAccount = Account::where('code', $this->accountCode('tax_payable'))->where('is_active', true)->first();
            $isCreditExpense = $expense->payment_method === 'credit';

            $lines = [
                ['account_id' => $expenseAccount->id, 'type' => 'debit', 'amount' => (float) $expense->subtotal, 'description' => 'Expense'],
            ];

            if ((float) $expense->tax_amount > 0 && $taxAccount !== null) {
                $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => (float) $expense->tax_amount, 'description' => 'Tax'];
            }

            $totalCredit = round((float) $expense->subtotal + (float) $expense->tax_amount, 6);
            $creditAccount = $isCreditExpense ? $payableAccount : $cashAccount;
            $creditDesc = $isCreditExpense ? 'Accounts Payable' : $cashAccount->name;

            $lines[] = ['account_id' => $creditAccount->id, 'type' => 'credit', 'amount' => $totalCredit, 'description' => $creditDesc];

            $entry = $this->createJournalEntry([
                'date'          => $expense->expense_date,
                'reference'     => $expense->expense_number,
                'type'          => 'general',
                'description'   => "Expense {$expense->expense_number}" . ($expense->vendor_name ? " - {$expense->vendor_name}" : ''),
                'currency'      => $expense->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $expense->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveExpenseSbuCode($expense),
                'lines'         => $lines,
            ]);

            $entry->post();
            $expense->update([
                'journal_entry_id' => $entry->id,
                'paid_amount'      => $isCreditExpense ? (float) $expense->paid_amount : (float) $expense->total,
                'status'           => $isCreditExpense ? 'approved' : 'paid',
            ]);

            return $entry;
        });
    }

    /**
     * Create and immediately post an expense linked to a specific invoice.
     *
     * Typical use: shipping out, courier fee, COD handling, or any fulfilment cost
     * borne by the company against a customer invoice.
     *
     * Journal entry:
     *   DR [expense_account]  (subtotal)
     *   DR Sales Tax (2300)   (tax_amount, if > 0)
     *   CR Cash (1000)        (payment_method != 'credit')
     *   CR AP   (2000)        (payment_method == 'credit')
     */
    public function recordInvoiceExpense(Invoice $invoice, array $data): Expense
    {
        return $this->recordDocumentExpense(
            $invoice,
            $invoice->invoice_number,
            fn () => $this->resolveInvoiceSbuCode($invoice),
            $data,
            'Invoice expense',
        );
    }

    /**
     * Create and immediately post an expense linked to a specific bill.
     *
     * Typical use: freight-in, customs duty, insurance, or any landed cost
     * associated with a vendor bill / purchase.
     *
     * Journal entry:
     *   DR [expense_account]  (subtotal)
     *   DR Sales Tax (2300)   (tax_amount, if > 0)
     *   CR Cash (1000)        (payment_method != 'credit')
     *   CR AP   (2000)        (payment_method == 'credit')
     */
    public function recordBillExpense(Bill $bill, array $data): Expense
    {
        return $this->recordDocumentExpense(
            $bill,
            $bill->bill_number,
            fn () => $this->resolveBillSbuCode($bill),
            $data,
            'Bill expense',
        );
    }

    /** Shared implementation for recordInvoiceExpense / recordBillExpense. */
    private function recordDocumentExpense(
        Invoice|Bill $document,
        string $documentNumber,
        \Closure $resolveSbu,
        array $data,
        string $defaultDescription,
    ): Expense {
        return DB::transaction(function () use ($document, $documentNumber, $resolveSbu, $data, $defaultDescription): Expense {
            $payload = $this->documentExpensePayload($document, $data, $defaultDescription);

            $expense = Expense::create([
                'chargeable_type' => $document::class,
                'chargeable_id'   => $document->id,
                'account_id'      => $data['account_id'] ?? null,
                'expense_date'    => $payload['date'],
                'subtotal'        => $payload['subtotal'],
                'tax_amount'      => $payload['tax_amount'],
                'total'           => $payload['total'],
                'paid_amount'     => 0,
                'currency'        => $payload['currency'],
                'exchange_rate'   => $payload['exchange_rate'],
                'status'          => 'draft',
                'payment_method'  => $payload['payment_method'],
                'vendor_name'     => $data['vendor_name'] ?? null,
                'reference'       => $data['reference'] ?? $documentNumber,
                'notes'           => $data['notes'] ?? null,
            ]);

            $accounts = $this->documentExpenseAccounts($expense->account_id, $payload['is_cash']);
            $lines = $this->documentExpenseJournalLines($payload, $accounts);

            $entry = $this->createJournalEntry([
                'date'          => $payload['date'],
                'reference'     => $expense->expense_number,
                'type'          => 'general',
                'description'   => "{$payload['description']} — {$documentNumber}",
                'currency'      => $payload['currency'],
                'exchange_rate' => $payload['exchange_rate'],
                'sbu_code'      => $this->normalizeSbuCode($data['sbu_code'] ?? null) ?? $resolveSbu(),
                'lines'         => $lines,
            ]);

            $entry->post();

            $expense->update([
                'journal_entry_id' => $entry->id,
                'paid_amount'      => $payload['is_cash'] ? $payload['total'] : 0,
                'status'           => $payload['is_cash'] ? 'paid' : 'approved',
            ]);

            return $expense;
        });
    }

    private function documentExpensePayload(Invoice|Bill $document, array $data, string $defaultDescription): array
    {
        $subtotal = round((float) ($data['amount'] ?? 0), 2);
        $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);

        return [
            'subtotal'       => $subtotal,
            'tax_amount'     => $taxAmount,
            'total'          => round($subtotal + $taxAmount, 2),
            'is_cash'        => ($data['payment_method'] ?? 'cash') !== 'credit',
            'payment_method' => $data['payment_method'] ?? 'cash',
            'currency'       => $data['currency'] ?? $document->currency ?? config('accounting.base_currency', 'BDT'),
            'exchange_rate'  => $data['exchange_rate'] ?? 1.0,
            'date'           => $data['date'] ?? $document->{'invoice_date'} ?? $document->{'bill_date'},
            'description'    => $data['description'] ?? $defaultDescription,
        ];
    }

    private function documentExpenseAccounts(?int $expenseAccountId, bool $isCash): array
    {
        $expenseAccount = $expenseAccountId
            ? (Account::find($expenseAccountId) ?? throw AccountNotFoundException::forCode('custom'))
            : $this->requireAccount($this->accountCode('cogs'));
        $cashAccount = $this->requireAccount($this->accountCode('cash'));
        $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));

        return [
            'expense' => $expenseAccount,
            'tax'     => Account::where('code', $this->accountCode('tax_payable'))->where('is_active', true)->first(),
            'credit'  => $isCash ? $cashAccount : $payableAccount,
        ];
    }

    private function documentExpenseJournalLines(array $payload, array $accounts): array
    {
        $lines = [
            ['account_id' => $accounts['expense']->id, 'type' => 'debit', 'amount' => $payload['subtotal'], 'description' => $payload['description']],
        ];

        if ($payload['tax_amount'] > 0 && $accounts['tax'] !== null) {
            $lines[] = ['account_id' => $accounts['tax']->id, 'type' => 'debit', 'amount' => $payload['tax_amount'], 'description' => 'Tax'];
        }

        $lines[] = [
            'account_id'  => $accounts['credit']->id,
            'type'        => 'credit',
            'amount'      => $payload['total'],
            'description' => $payload['is_cash'] ? 'Cash paid' : 'Accounts Payable',
        ];

        return $lines;
    }

    /** Record settlement of a credit expense: DR AP / CR Cash. */
    public function recordExpensePayment(Expense $expense, array $paymentData): Payment
    {
        return DB::transaction(function () use ($expense, $paymentData): Payment {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);

            $amount = (float) $paymentData['amount'];
            $outstanding = round((float) $expense->total - (float) $expense->paid_amount, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
            }

            if (
                Payment::where('payable_type', Expense::class)
                    ->where('payable_id', $expense->id)
                    ->where('amount', $amount)
                    ->whereDate('payment_date', $paymentData['date'])
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forExpense($expense->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Expense::class,
                'payable_id'     => $expense->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? $this->accountCode('cash'));

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Expense payment {$expense->expense_number}",
                'currency'    => $expense->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->resolveExpenseSbuCode($expense, $paymentData),
                'lines'       => [
                    ['account_id' => $payableAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => 'Expense payable'],
                    ['account_id' => $cashAccount->id,    'type' => 'credit', 'amount' => $amount, 'description' => 'Cash paid'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            $newPaid = round((float) $expense->paid_amount + $amount, 6);
            $newStatus = $newPaid >= (float) $expense->total - $this->tolerance() ? 'paid' : 'partial';

            $expense->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });
    }

    // -------------------------------------------------------------------------
    // Financial Reports  (N+1 fixed — all use buildBalanceMap)
    // -------------------------------------------------------------------------

    /** Generate Trial Balance. Uses a single aggregated SQL query instead of N+1. */
    public function getTrialBalance(mixed $startDate = null, mixed $endDate = null, ?string $sbuCode = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::where('is_active', true)->orderBy('code')->get();
        $balanceMap = $this->buildBalanceMap($startDate, $endDate, $sbuCode);

        $trialBalance = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($accounts as $account) {
            $row = $balanceMap->get($account->id);
            $debits = (float) ($row?->total_debit ?? 0);
            $credits = (float) ($row?->total_credit ?? 0);

            $balance = $account->isDebitAccount()
                ? ($debits - $credits)
                : ($credits - $debits);

            if (abs($balance) > $tolerance) {
                // Debit-normal (asset/expense): positive balance → debit column.
                // Credit-normal (liability/equity/revenue): positive balance → credit column.
                $isDebit = $account->isDebitAccount();
                $debitAmt = $isDebit ? ($balance > 0 ? $balance : 0) : ($balance < 0 ? -$balance : 0);
                $creditAmt = $isDebit ? ($balance < 0 ? -$balance : 0) : ($balance > 0 ? $balance : 0);

                $trialBalance[] = [
                    'account' => $account,
                    'debit'   => $debitAmt,
                    'credit'  => $creditAmt,
                ];

                $totalDebits += $debitAmt;
                $totalCredits += $creditAmt;
            }
        }

        return [
            'accounts'      => $trialBalance,
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'is_balanced'   => abs($totalDebits - $totalCredits) < ($tolerance * 2),
            'sbu_code'      => $this->normalizeSbuCode($sbuCode),
        ];
    }

    /** Generate Balance Sheet (point-in-time). */
    public function getBalanceSheet(mixed $date = null, ?string $sbuCode = null): array
    {
        $date ??= now();

        $assets = $this->getAccountsByType('asset', $date, null, $sbuCode);
        $liabilities = $this->getAccountsByType('liability', $date, null, $sbuCode);
        $equity = $this->getAccountsByType('equity', $date, null, $sbuCode);

        $netIncome = $this->getNetIncome(null, $date, $sbuCode);
        $retainedEarnings = ($equity['total'] ?? 0) + $netIncome;

        return [
            'date'        => $date,
            'assets'      => $assets,
            'liabilities' => $liabilities,
            'equity'      => array_merge($equity, [
                'net_income'        => $netIncome,
                'retained_earnings' => $retainedEarnings,
                'total_with_income' => ($liabilities['total'] ?? 0) + $retainedEarnings,
            ]),
            'sbu_code'    => $this->normalizeSbuCode($sbuCode),
            'is_balanced' => abs(
                ($assets['total'] ?? 0) - (($liabilities['total'] ?? 0) + $retainedEarnings),
            ) < ($this->tolerance() * 2),
        ];
    }

    /** Generate Income Statement (P&L). */
    public function getIncomeStatement(mixed $startDate, mixed $endDate, ?string $sbuCode = null): array
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate, $sbuCode);
        $cogs = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode, ['cost_of_goods_sold']);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode, [], ['cost_of_goods_sold']);

        $grossProfit = ($revenue['total'] ?? 0) - ($cogs['total'] ?? 0);

        return [
            'period'       => ['start' => $startDate, 'end' => $endDate],
            'revenue'      => $revenue,
            'cogs'         => $cogs,
            'expenses'     => $expenses,
            'gross_profit' => $grossProfit,
            'net_income'   => $grossProfit - ($expenses['total'] ?? 0),
            'sbu_code'     => $this->normalizeSbuCode($sbuCode),
        ];
    }

    /**
     * Cash Flow Statement (indirect method).
     *
     * Operating = net income + working-capital adjustments (AR, AP, Inventory).
     * Investing  = changes in non-current assets (codes ≥ 1500).
     * Financing  = changes in long-term liabilities (codes ≥ 2500) + equity.
     */
    public function getCashFlowStatement(mixed $startDate = null, mixed $endDate = null, ?string $sbuCode = null): array
    {
        $start = $startDate ? Carbon::parse($startDate) : now()->startOfYear();
        $end = $endDate ? Carbon::parse($endDate) : now();

        $opening = $this->getBalanceSheet($start->copy()->subDay()->toDateString(), $sbuCode);
        $closing = $this->getBalanceSheet($end->toDateString(), $sbuCode);

        $income = $this->getIncomeStatement($start->toDateString(), $end->toDateString(), $sbuCode);
        $netIncome = (float) ($income['net_income'] ?? 0);

        $balanceMap = static fn (array $section): array => collect($section['accounts'] ?? [])
            ->keyBy(fn ($item) => (string) $item['account']->code)
            ->map(fn ($item) => (float) ($item['balance'] ?? 0))
            ->all();

        $openAssets = $balanceMap($opening['assets'] ?? []);
        $closeAssets = $balanceMap($closing['assets'] ?? []);
        $openLiab = $balanceMap($opening['liabilities'] ?? []);
        $closeLiab = $balanceMap($closing['liabilities'] ?? []);
        $openEquity = $balanceMap($opening['equity'] ?? []);
        $closeEquity = $balanceMap($closing['equity'] ?? []);

        $operatingAdj = 0.0;
        $investingActivities = 0.0;
        $financingActivities = 0.0;

        // Collect account name lookups from both balance sheets for breakdown labels
        $accountNames = [];

        foreach (array_merge(
            $opening['assets']['accounts'] ?? [],
            $closing['assets']['accounts'] ?? [],
            $opening['liabilities']['accounts'] ?? [],
            $closing['liabilities']['accounts'] ?? [],
            $opening['equity']['accounts'] ?? [],
            $closing['equity']['accounts'] ?? [],
        ) as $row) {
            $accountNames[(string) $row['account']->code] = $row['account']->name;
        }

        $wcChanges = [];
        $investingDetails = [];
        $financingDetails = [];

        foreach (array_unique(array_merge(array_keys($openAssets), array_keys($closeAssets))) as $code) {
            $strCode = (string) $code;
            $delta = ($closeAssets[$strCode] ?? 0.0) - ($openAssets[$strCode] ?? 0.0);

            if ((int) $strCode < 1500) {
                if ((int) $strCode >= 1200) {
                    $adj = -$delta; // increase in AR/inventory = cash used
                    $operatingAdj += $adj;

                    if (abs($adj) > 0.001) {
                        $wcChanges[] = ['code' => $strCode, 'name' => $accountNames[$strCode] ?? $strCode, 'amount' => round($adj, 2)];
                    }
                }
                // codes 1000–1199 (cash/bank) are intentionally excluded — they are what we're measuring
            } else {
                $adj = -$delta; // increase in fixed assets = cash used
                $investingActivities += $adj;

                if (abs($adj) > 0.001) {
                    $investingDetails[] = ['code' => $strCode, 'name' => $accountNames[$strCode] ?? $strCode, 'amount' => round($adj, 2)];
                }
            }
        }

        foreach (array_unique(array_merge(array_keys($openLiab), array_keys($closeLiab))) as $code) {
            $strCode = (string) $code;
            $delta = ($closeLiab[$strCode] ?? 0.0) - ($openLiab[$strCode] ?? 0.0);

            if ((int) $strCode < 2500) {
                $operatingAdj += $delta; // increase in current liabilities = cash provided

                if (abs($delta) > 0.001) {
                    $wcChanges[] = ['code' => $strCode, 'name' => $accountNames[$strCode] ?? $strCode, 'amount' => round($delta, 2)];
                }
            } else {
                $financingActivities += $delta; // increase in LT liabilities = financing

                if (abs($delta) > 0.001) {
                    $financingDetails[] = ['code' => $strCode, 'name' => $accountNames[$strCode] ?? $strCode, 'amount' => round($delta, 2)];
                }
            }
        }

        foreach (array_unique(array_merge(array_keys($openEquity), array_keys($closeEquity))) as $code) {
            $strCode = (string) $code;
            $delta = ($closeEquity[$strCode] ?? 0.0) - ($openEquity[$strCode] ?? 0.0);
            $financingActivities += $delta;

            if (abs($delta) > 0.001) {
                $financingDetails[] = ['code' => $strCode, 'name' => $accountNames[$strCode] ?? $strCode, 'amount' => round($delta, 2)];
            }
        }

        $operatingTotal = $netIncome + $operatingAdj;
        $netChange = $operatingTotal + $investingActivities + $financingActivities;

        return [
            'period'               => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'operating_activities' => round($operatingTotal, 2),
            'investing_activities' => round($investingActivities, 2),
            'financing_activities' => round($financingActivities, 2),
            'net_change'           => round($netChange, 2),
            'sbu_code'             => $this->normalizeSbuCode($sbuCode),
            // Breakdown — shows how invoice payments, AR changes, and other items contribute
            'operating_breakdown' => [
                'net_income'                  => round($netIncome, 2),
                'working_capital_adjustments' => round($operatingAdj, 2),
                'changes_in_working_capital'  => $wcChanges,
            ],
            'investing_breakdown' => $investingDetails,
            'financing_breakdown' => $financingDetails,
        ];
    }

    /**
     * Generate General Ledger.
     *
     * Returns posted journal lines grouped by account, with opening and running
     * balances using the account's normal balance side.
     */
    public function getGeneralLedger(?int $accountId = null, mixed $startDate = null, mixed $endDate = null, ?string $sbuCode = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::query()
            ->where('is_active', true)
            ->when($accountId !== null, fn ($q) => $q->whereKey($accountId))
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return [
                'period'   => ['start' => $startDate, 'end' => $endDate],
                'accounts' => [],
                'sbu_code' => $this->normalizeSbuCode($sbuCode),
            ];
        }

        $prefix = config('accounting.table_prefix', 'acct_');
        $accountIds = $accounts->pluck('id')->all();

        $openingMap = collect();

        if ($startDate !== null) {
            $openingQuery = DB::table("{$prefix}journal_entry_lines as l")
                ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
                ->where('je.status', 'posted')
                ->whereIn('l.account_id', $accountIds)
                ->whereDate('je.date', '<', $startDate)
                ->groupBy('l.account_id')
                ->selectRaw(
                    "l.account_id,
                    SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE 0 END) as total_debit,
                    SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit",
                );

            $openingMap = $this->applySbuFilter($openingQuery, $sbuCode)->get()->keyBy('account_id');
        }

        $lineQuery = DB::table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('l.account_id', $accountIds)
            ->when($startDate !== null, fn ($q) => $q->whereDate('je.date', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->whereDate('je.date', '<=', $endDate))
            ->orderBy('l.account_id')
            ->orderBy('je.date')
            ->orderBy('je.id')
            ->orderBy('l.id')
            ->select([
                'l.id as line_id',
                'l.account_id',
                'l.type',
                'l.amount',
                'l.description as line_description',
                'l.reference as line_reference',
                'je.id as journal_entry_id',
                'je.entry_number',
                'je.date',
                'je.reference as journal_reference',
                'je.description as journal_description',
                'je.type as journal_type',
                'je.sbu_code',
            ]);

        $lineMap = $this->applySbuFilter($lineQuery, $sbuCode)->get()->groupBy('account_id');

        $ledgerAccounts = [];

        foreach ($accounts as $account) {
            $openingRow = $openingMap->get($account->id);
            $openingDebits = (float) ($openingRow?->total_debit ?? 0);
            $openingCredits = (float) ($openingRow?->total_credit ?? 0);
            $openingBalance = $account->isDebitAccount()
                ? ($openingDebits - $openingCredits)
                : ($openingCredits - $openingDebits);

            $runningBalance = $openingBalance;
            $periodDebits = 0.0;
            $periodCredits = 0.0;
            $entries = [];

            foreach ($lineMap->get($account->id, collect()) as $row) {
                $debit = $row->type === 'debit' ? (float) $row->amount : 0.0;
                $credit = $row->type === 'credit' ? (float) $row->amount : 0.0;
                $delta = $account->isDebitAccount()
                    ? ($debit - $credit)
                    : ($credit - $debit);

                $periodDebits += $debit;
                $periodCredits += $credit;
                $runningBalance += $delta;

                $entries[] = [
                    'line_id'             => (int) $row->line_id,
                    'journal_entry_id'    => (int) $row->journal_entry_id,
                    'entry_number'        => $row->entry_number,
                    'date'                => $row->date,
                    'reference'           => $row->line_reference ?: $row->journal_reference,
                    'journal_type'        => $row->journal_type,
                    'journal_description' => $row->journal_description,
                    'line_description'    => $row->line_description,
                    'sbu_code'            => $row->sbu_code,
                    'debit'               => $debit,
                    'credit'              => $credit,
                    'running_balance'     => $runningBalance,
                ];
            }

            $closingBalance = $runningBalance;

            if (
                $accountId === null
                && abs($openingBalance) < $tolerance
                && abs($closingBalance) < $tolerance
                && $entries === []
            ) {
                continue;
            }

            $ledgerAccounts[] = [
                'account'         => $account,
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'period_debits'   => $periodDebits,
                'period_credits'  => $periodCredits,
                'entries'         => $entries,
            ];
        }

        return [
            'period'   => ['start' => $startDate, 'end' => $endDate],
            'accounts' => $ledgerAccounts,
            'sbu_code' => $this->normalizeSbuCode($sbuCode),
        ];
    }

    /**
     * Sales tax liability report: output tax collected (invoices) vs input tax paid
     * (bills) over a period, grouped by TaxRate. Lines with no linked TaxRate (the
     * free-typed fallback) are grouped into an "Unassigned / Ad-hoc" bucket.
     *
     * Read-only aggregation over invoice_items/bill_items.tax_amount — does not
     * touch postInvoice()/postBill() or the tax_payable GL account.
     */
    public function getSalesTaxLiabilityReport(mixed $startDate, mixed $endDate, ?string $sbuCode = null): array
    {
        $sbuCode = $this->normalizeSbuCode($sbuCode);

        $collected = DB::table((new InvoiceItem())->getTable() . ' as ii')
            ->join((new Invoice())->getTable() . ' as i', 'i.id', '=', 'ii.invoice_id')
            ->whereNotIn('i.status', ['draft', 'void'])
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->when($sbuCode !== null, fn ($q) => $q->where('i.sbu_code', $sbuCode))
            ->groupBy('ii.tax_rate_id')
            ->selectRaw('ii.tax_rate_id, SUM(ii.tax_amount) as total')
            ->get()
            ->keyBy(fn ($row) => $row->tax_rate_id ?? 0);

        $paid = DB::table((new BillItem())->getTable() . ' as bi')
            ->join((new Bill())->getTable() . ' as b', 'b.id', '=', 'bi.bill_id')
            ->whereNotIn('b.status', ['draft', 'void'])
            ->whereBetween('b.bill_date', [$startDate, $endDate])
            ->when($sbuCode !== null, fn ($q) => $q->where('b.sbu_code', $sbuCode))
            ->groupBy('bi.tax_rate_id')
            ->selectRaw('bi.tax_rate_id, SUM(bi.tax_amount) as total')
            ->get()
            ->keyBy(fn ($row) => $row->tax_rate_id ?? 0);

        $taxRateIds = $collected->keys()->merge($paid->keys())->filter(fn ($id) => $id !== 0)->unique();
        $taxRates = TaxRate::whereIn('id', $taxRateIds)->get()->keyBy('id');

        $rows = [];
        $totalCollected = 0.0;
        $totalPaid = 0.0;

        foreach ($collected->keys()->merge($paid->keys())->unique() as $key) {
            $taxRate = $key !== 0 ? $taxRates->get($key) : null;
            $rowCollected = (float) ($collected->get($key)?->total ?? 0);
            $rowPaid = (float) ($paid->get($key)?->total ?? 0);

            $rows[] = [
                'tax_rate_id' => $taxRate?->id,
                'name'        => $taxRate?->name ?? 'Unassigned / Ad-hoc',
                'code'        => $taxRate?->code,
                'rate'        => $taxRate?->rate,
                'collected'   => $rowCollected,
                'paid'        => $rowPaid,
                'net_payable' => round($rowCollected - $rowPaid, 2),
            ];

            $totalCollected += $rowCollected;
            $totalPaid += $rowPaid;
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'period'            => ['start' => $startDate, 'end' => $endDate],
            'rows'              => $rows,
            'total_collected'   => round($totalCollected, 2),
            'total_paid'        => round($totalPaid, 2),
            'total_net_payable' => round($totalCollected - $totalPaid, 2),
            'sbu_code'          => $sbuCode,
        ];
    }

    // -------------------------------------------------------------------------
    // Chart of Accounts
    // -------------------------------------------------------------------------

    /** Initialize standard Chart of Accounts (idempotent). */
    public function initializeChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                    'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1100', 'name' => 'Bank Account',            'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',     'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory',               'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1500', 'name' => 'Prepaid Expenses',        'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1700', 'name' => 'Fixed Assets',            'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '1800', 'name' => 'Accumulated Depreciation', 'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',        'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2050', 'name' => 'Goods Received Not Invoiced', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2100', 'name' => 'Credit Card Payable',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2150', 'name' => 'Inventory Financing Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2170', 'name' => 'Accrued Interest — Inventory Financing', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2200', 'name' => 'Accrued Expenses',        'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',              'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2400', 'name' => 'Short-term Loans Payable',      'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2420', 'name' => 'Accrued Interest — Short-term Loans', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Loans Payable',       'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '2520', 'name' => 'Accrued Interest — Long-term Loans',  'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '3000', 'name' => "Owner's Equity",          'type' => 'equity',    'subtype' => 'capital_account'],
            ['code' => '3100', 'name' => 'Retained Earnings',       'type' => 'equity',    'subtype' => 'retained_earnings_account'],
            ['code' => '3200', 'name' => "Owner's Draw",            'type' => 'equity',    'subtype' => 'drawings_account'],
            ['code' => '4000', 'name' => 'Sales Revenue',           'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '4100', 'name' => 'Service Revenue',         'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '4900', 'name' => 'Other Income',            'type' => 'revenue',   'subtype' => 'non_operating_revenue'],
            ['code' => '4910', 'name' => 'Gain/Loss on Disposal of Fixed Assets', 'type' => 'revenue', 'subtype' => 'non_operating_revenue'],
            ['code' => '4210', 'name' => 'Delivery Charge',         'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '4220', 'name' => 'Cash on Delivery Charge', 'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold',      'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
            ['code' => '5500', 'name' => 'Purchase Discount',       'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
            ['code' => '5501', 'name' => 'Early Payment Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5502', 'name' => 'Volume Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5503', 'name' => 'Trade Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5504', 'name' => 'Purchase Returns & Allowances', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '6000', 'name' => 'Salaries & Wages',        'type' => 'expense',   'subtype' => 'salaries_and_wages_expense'],
            ['code' => '6100', 'name' => 'Rent Expense',            'type' => 'expense',   'subtype' => 'rent_expense'],
            ['code' => '6130', 'name' => 'Sales Discount',          'type' => 'expense',   'subtype' => 'selling_expense'],
            ['code' => '6131', 'name' => 'Early Payment Discount (Sales)', 'type' => 'expense', 'subtype' => 'selling_expense'],
            ['code' => '6132', 'name' => 'Volume Discount (Sales)', 'type' => 'expense', 'subtype' => 'selling_expense'],
            ['code' => '6133', 'name' => 'Promotional Discount (Sales)', 'type' => 'expense', 'subtype' => 'selling_expense'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'expense', 'subtype' => 'selling_expense'],
            ['code' => '6200', 'name' => 'Utilities',               'type' => 'expense',   'subtype' => 'utilities_expense'],
            ['code' => '6300', 'name' => 'Office Supplies',         'type' => 'expense',   'subtype' => 'office_supplies_expense'],
            ['code' => '6310', 'name' => 'Courier Bill / Charge',   'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6320', 'name' => 'Shipping / Transfer Bill (Carriage)', 'type' => 'expense', 'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6330', 'name' => 'Local Delivery Charge',    'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6340', 'name' => 'Delivery Return Charge',  'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6400', 'name' => 'Insurance',               'type' => 'expense',   'subtype' => 'insurance_expense'],
            ['code' => '6500', 'name' => 'Marketing & Advertising', 'type' => 'expense',   'subtype' => 'marketing_expense'],
            ['code' => '6600', 'name' => 'Depreciation',            'type' => 'expense',   'subtype' => 'depreciation_expense'],
            ['code' => '6700', 'name' => 'Interest Expense',        'type' => 'expense',   'subtype' => 'interest_expense'],
            ['code' => '6710', 'name' => 'Interest Expense — Inventory Financing', 'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6720', 'name' => 'Interest Expense — Short-term Loans',   'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6730', 'name' => 'Interest Expense — Long-term Loans',    'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6800', 'name' => 'Bank Fees',               'type' => 'expense',   'subtype' => 'bank_fees_expense'],
            ['code' => '7100', 'name' => 'Consultancy Fee',         'type' => 'expense',   'subtype' => 'consulting_expense'],
            ['code' => '7200', 'name' => 'Donation Expense',        'type' => 'expense',   'subtype' => 'donation_expense'],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['code' => $accountData['code']],
                array_merge($accountData, ['is_system' => true]),
            );
        }

        // Wire parent relationships for sub-accounts
        $parentMap = [
            '6710' => '6700',  // Inv. Financing Interest → Interest Expense
            '6720' => '6700',  // Short-term Loan Interest → Interest Expense
            '6730' => '6700',  // Long-term Loan Interest → Interest Expense
        ];

        foreach ($parentMap as $childCode => $parentCode) {
            $parent = Account::where('code', $parentCode)->first();
            $child = Account::where('code', $childCode)->first();

            if ($parent && $child && $child->parent_id === null) {
                $child->update(['parent_id' => $parent->id]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Fiscal Year
    // -------------------------------------------------------------------------

    /**
     * Close fiscal year: transfer net income to retained earnings.
     * Locks the FiscalYear row to prevent concurrent closure.
     */
    public function closeFiscalYear(FiscalYear $fiscalYear): void
    {
        DB::transaction(function () use ($fiscalYear): void {
            // Pessimistic lock prevents two concurrent close requests
            $fiscalYear = FiscalYear::lockForUpdate()->findOrFail($fiscalYear->id);

            if ($fiscalYear->is_closed) {
                throw InvalidStatusTransitionException::make('FiscalYear', 'closed', 'closed');
            }

            $netIncome = $this->getNetIncome($fiscalYear->start_date, $fiscalYear->end_date);

            $retainedEarnings = $this->requireAccount($this->accountCode('retained_earnings'));

            $incomeSummary = Account::where('code', '3900')->first()
                ?? Account::create([
                    'code'      => '3900',
                    'name'      => 'Income Summary',
                    'type'      => 'equity',
                    'subtype'   => 'memorandum_account',
                    'is_system' => true,
                ]);

            if (abs($netIncome) > $this->tolerance()) {
                $entry = $this->createJournalEntry([
                    'date'        => $fiscalYear->end_date,
                    'reference'   => 'YE-' . $fiscalYear->name,
                    'type'        => 'closing',
                    'description' => "Closing entry for fiscal year {$fiscalYear->name}",
                    'lines'       => [
                        [
                            'account_id'  => $incomeSummary->id,
                            'type'        => $netIncome > 0 ? 'debit' : 'credit',
                            'amount'      => abs($netIncome),
                            'description' => 'Income Summary',
                        ],
                        [
                            'account_id'  => $retainedEarnings->id,
                            'type'        => $netIncome > 0 ? 'credit' : 'debit',
                            'amount'      => abs($netIncome),
                            'description' => 'Retained Earnings',
                        ],
                    ],
                ]);

                $entry->post(bypassPeriodLock: true);
            }

            $fiscalYear->update(['is_closed' => true]);
        });
    }

    // -------------------------------------------------------------------------
    // Period (Month-End) Closing
    // -------------------------------------------------------------------------

    /**
     * Pre-close checks for a fiscal period.
     * Returns counts of items that block or warn before closing.
     */
    public function getPeriodCloseChecks(FiscalPeriod $period): array
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $db = DB::connection($connection);

        $unpostedJournals = $db->table("{$prefix}journal_entries")
            ->whereNull('deleted_at')
            ->where('status', 'draft')
            ->whereDate('date', '>=', $period->start_date)
            ->whereDate('date', '<=', $period->end_date)
            ->count();

        $openInvoices = $db->table("{$prefix}invoices")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->whereDate('invoice_date', '>=', $period->start_date)
            ->whereDate('invoice_date', '<=', $period->end_date)
            ->count();

        $openBills = $db->table("{$prefix}bills")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->whereDate('bill_date', '>=', $period->start_date)
            ->whereDate('bill_date', '<=', $period->end_date)
            ->count();

        return [
            'unposted_journals' => $unpostedJournals,
            'open_invoices'     => $openInvoices,
            'open_bills'        => $openBills,
            'has_blockers'      => $unpostedJournals > 0,
            'has_warnings'      => $openInvoices > 0 || $openBills > 0,
        ];
    }

    /**
     * Close a fiscal period: snapshot GL balances, optionally snapshot inventory WAC/qty, then lock the period.
     *
     * @return array{period: FiscalPeriod, inventory: array|null}
     */
    public function closeFiscalPeriod(FiscalPeriod $period, bool $snapshotInventory = true): array
    {
        return DB::transaction(function () use ($period, $snapshotInventory): array {
            $period = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($period->is_closed) {
                throw InvalidStatusTransitionException::make('FiscalPeriod', 'closed', 'closed');
            }

            $inventoryResult = null;

            if ($snapshotInventory && $this->inventoryIntegrationEnabled()) {
                $inventoryResult = $this->takeInventoryPeriodSnapshot($period);
            }

            $this->upsertAccountBalancesForPeriod($period);

            $period->update(['is_closed' => true]);

            return [
                'period'    => $period->fresh(),
                'inventory' => $inventoryResult,
            ];
        });
    }

    private function inventoryIntegrationEnabled(): bool
    {
        return $this->inventorySnapshotProvider() instanceof InventorySnapshotProvider;
    }

    /**
     * Snapshot WAC and qty_on_hand per warehouse+product at period-end and reconcile against GL.
     */
    private function takeInventoryPeriodSnapshot(FiscalPeriod $period): array
    {
        $currency = $this->baseCurrency();
        $snapshot = $this->inventorySnapshotProvider()?->snapshotForPeriod($period, $currency) ?? [];

        // Idempotent: remove any previous snapshot for this period before re-inserting
        PeriodInventorySnapshot::where('fiscal_period_id', $period->id)->delete();

        $rows = collect($snapshot['rows'] ?? [])
            ->map(function (array $row) use ($period, $currency): array {
                return array_merge($row, [
                    'fiscal_period_id' => $period->id,
                    'currency'         => $row['currency'] ?? $currency,
                    'snapshot_date'    => $row['snapshot_date'] ?? $period->end_date,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            })
            ->toArray();

        if ($rows !== []) {
            PeriodInventorySnapshot::insert($rows);
        }

        $physicalValue = (float) collect($rows)->sum('total_value');
        $inventoryAccountCode = (string) ($snapshot['inventory_account_code'] ?? $this->accountCode('inventory'));
        $inventoryAccountCode = $inventoryAccountCode !== '' ? $inventoryAccountCode : '1300';
        $glBalance = $this->getAccountGlBalance($inventoryAccountCode, $period->end_date);
        $variance = round($physicalValue - $glBalance, 2);

        return [
            'snapshot_count' => count($rows),
            'physical_value' => $physicalValue,
            'gl_balance'     => $glBalance,
            'variance'       => $variance,
            'is_reconciled'  => abs($variance) < 1.0,
            'currency'       => $currency,
        ];
    }

    private function inventorySnapshotProvider(): ?InventorySnapshotProvider
    {
        $providerClass = config('accounting.integrations.inventory.snapshot_provider');

        if (!is_string($providerClass) || $providerClass === '' || !class_exists($providerClass)) {
            return null;
        }

        $provider = app($providerClass);

        return $provider instanceof InventorySnapshotProvider ? $provider : null;
    }

    /** Get the net GL running balance of an account code up to (and including) a given date. */
    private function getAccountGlBalance(string $accountCode, mixed $asOfDate): float
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $account = Account::where('code', $accountCode)->first();

        if (!$account) {
            return 0.0;
        }

        $row = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->where('l.account_id', $account->id)
            ->when($asOfDate, fn ($q) => $q->whereDate('je.date', '<=', $asOfDate))
            ->selectRaw("SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE -l.amount END) as balance")
            ->first();

        return round((float) ($row->balance ?? 0), 2);
    }

    /** Compute period-level debit/credit totals and upsert them into acct_account_balances. */
    private function upsertAccountBalancesForPeriod(FiscalPeriod $period): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        $rows = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->whereDate('je.date', '>=', $period->start_date)
            ->whereDate('je.date', '<=', $period->end_date)
            ->select([
                'l.account_id',
                DB::raw("SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END) as debit"),
                DB::raw("SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as credit"),
            ])
            ->groupBy('l.account_id')
            ->get();

        foreach ($rows as $row) {
            AccountBalance::updateOrCreate(
                ['account_id' => $row->account_id, 'fiscal_period_id' => $period->id],
                [
                    'debit'   => $row->debit,
                    'credit'  => $row->credit,
                    'balance' => (float) $row->debit - (float) $row->credit,
                ],
            );
        }
    }

    // -------------------------------------------------------------------------
    // Budgets
    // -------------------------------------------------------------------------

    /** Create a budget with line items. */
    public function createBudget(array $data): Budget
    {
        return DB::transaction(function () use ($data): Budget {
            $budget = Budget::create([
                'name'           => $data['name'],
                'fiscal_year_id' => $data['fiscal_year_id'] ?? null,
                'period_start'   => $data['period_start'],
                'period_end'     => $data['period_end'],
                'total_amount'   => $data['total_amount'],
                'currency'       => $data['currency'] ?? config('accounting.base_currency', 'BDT'),
                'status'         => 'draft',
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                $budget->items()->create([
                    'account_id'   => $item['account_id'],
                    'description'  => $item['description'] ?? null,
                    'amount'       => $item['amount'],
                    'period_start' => $item['period_start'] ?? null,
                    'period_end'   => $item['period_end'] ?? null,
                ]);
            }

            return $budget;
        });
    }

    /** Approve a budget (wrapped in a transaction to prevent concurrent approvals). */
    public function approveBudget(Budget $budget, ?int $userId = null): Budget
    {
        $this->assertBudgetApprovalAuthorized();

        return DB::transaction(function () use ($budget, $userId): Budget {
            $budget = Budget::lockForUpdate()->findOrFail($budget->id);

            if ($budget->status === 'approved') {
                throw InvalidStatusTransitionException::make('Budget', 'approved', 'approved');
            }

            $budget->approve($userId ?? auth()->id());

            return $budget;
        });
    }

    /**
     * Budget approval is a high-level sign-off (accounting.budget.approve — General Manager by
     * default) — separate from accounting.budget.manage, since drafting a budget and approving
     * spend against it shouldn't require the same trust level. Skipped for console callers
     * (seeders, artisan, demo data commands) — there's no web user to check, and CLI access is
     * already a higher trust boundary.
     */
    private function assertBudgetApprovalAuthorized(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (Gate::forUser(auth()->user())->denies('accounting.budget.approve')) {
            throw new AuthorizationException('Only a General Manager (or equivalent) can approve this budget.');
        }
    }

    /**
     * Get budget vs actual comparison.
     * BudgetItem::$spent is an accessor that runs a query — load it with a subquery
     * sum to avoid N+1 (one query per item).
     */
    public function getBudgetVsActual(Budget $budget): array
    {
        $items = $budget->items()->with('account')->get();

        // Pre-populate _spent_cache for all items in a single query — avoids N+1
        BudgetItem::loadSpentAmounts($items, $budget->period_start, $budget->period_end);

        $comparison = [];
        $totalBudgeted = 0.0;
        $totalActual = 0.0;

        foreach ($items as $item) {
            $actual = (float) $item->spent;
            $budgeted = (float) $item->amount;
            $variance = $budgeted - $actual;
            $percentageUsed = $item->percentage_used;

            $comparison[] = [
                'item'       => $item,
                'account'    => $item->account,
                'budgeted'   => $budgeted,
                'actual'     => $actual,
                'variance'   => $variance,
                'percentage' => $percentageUsed,
                'status'     => $percentageUsed > 100 ? 'over' : ($percentageUsed > 80 ? 'warning' : 'ok'),
            ];

            $totalBudgeted += $budgeted;
            $totalActual += $actual;
        }

        return [
            'budget'         => $budget,
            'items'          => $comparison,
            'total_budgeted' => $totalBudgeted,
            'total_actual'   => $totalActual,
            'total_variance' => $totalBudgeted - $totalActual,
        ];
    }

    /** Get budget summary across all approved budgets in a date range. */
    public function getBudgetSummary(string $startDate, string $endDate): array
    {
        $budgets = Budget::where('status', 'approved')
            ->whereDate('period_start', '<=', $endDate)
            ->whereDate('period_end', '>=', $startDate)
            ->with(['items.account', 'fiscalYear'])
            ->get();

        $summary = [];

        foreach ($budgets as $budget) {
            foreach ($budget->items as $item) {
                $key = $item->account_id;

                if (!isset($summary[$key])) {
                    $summary[$key] = ['account' => $item->account, 'budgeted' => 0.0, 'actual' => 0.0, 'variance' => 0.0];
                }

                $summary[$key]['budgeted'] += (float) $item->amount;
                $summary[$key]['actual'] += (float) $item->spent;
            }
        }

        foreach ($summary as $key => $data) {
            $summary[$key]['variance'] = $data['budgeted'] - $data['actual'];
        }

        return array_values($summary);
    }

    // -------------------------------------------------------------------------
    // Requisitions
    // -------------------------------------------------------------------------

    /**
     * Create a new purchase or expense requisition with line items.
     *
     * @param  array{
     *   type: 'purchase'|'expense',
     *   title: string,
     *   description?: string|null,
     *   vendor_id?: int|null,
     *   account_id?: int|null,
     *   requested_by?: string|null,
     *   requested_date: string,
     *   required_date?: string|null,
     *   currency?: string,
     *   notes?: string|null,
     *   items: array<array{description: string, quantity: float, unit_price: float}>,
     * }  $data
     */
    public function createRequisition(array $data): Requisition
    {
        return DB::transaction(function () use ($data): Requisition {
            $items = $data['items'] ?? [];
            $total = collect($items)->sum(fn ($i) => (float) ($i['quantity'] ?? 1) * (float) ($i['unit_price'] ?? 0));

            $req = Requisition::create([
                'type'           => $data['type'],
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'vendor_id'      => $data['vendor_id'] ?? null,
                'account_id'     => $data['account_id'] ?? null,
                'requested_by'   => $data['requested_by'] ?? null,
                'requested_date' => $data['requested_date'],
                'required_date'  => $data['required_date'] ?? null,
                'total_amount'   => $total,
                'currency'       => strtoupper((string) ($data['currency'] ?? $this->baseCurrency())),
                'notes'          => $data['notes'] ?? null,
                'status'         => RequisitionStatus::DRAFT,
            ]);

            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price'] ?? 0);

                RequisitionItem::create([
                    'requisition_id' => $req->id,
                    'description'    => $item['description'],
                    'quantity'       => $qty,
                    'unit_price'     => $price,
                    'total'          => round($qty * $price, 2),
                ]);
            }

            return $req->fresh('items');
        });
    }

    /** Advance requisition from draft → submitted. */
    public function submitRequisition(Requisition $requisition, ?int $userId = null): Requisition
    {
        if ($requisition->status !== RequisitionStatus::DRAFT) {
            throw new InvalidStatusTransitionException(
                "Cannot submit a requisition with status [{$requisition->status->value}].",
            );
        }

        $requisition->submit($userId ?? auth()->id());

        return $requisition->fresh();
    }

    /** Advance requisition from submitted → approved. */
    public function approveRequisition(Requisition $requisition, ?int $userId = null): Requisition
    {
        if ($requisition->status !== RequisitionStatus::SUBMITTED) {
            throw new InvalidStatusTransitionException(
                "Cannot approve a requisition with status [{$requisition->status->value}].",
            );
        }

        $requisition->approve($userId ?? auth()->id());

        return $requisition->fresh();
    }

    /** Reject a submitted requisition. */
    public function rejectRequisition(Requisition $requisition, string $reason, ?int $userId = null): Requisition
    {
        if ($requisition->status !== RequisitionStatus::SUBMITTED) {
            throw new InvalidStatusTransitionException(
                "Cannot reject a requisition with status [{$requisition->status->value}].",
            );
        }

        $requisition->reject($reason, $userId ?? auth()->id());

        return $requisition->fresh();
    }

    /**
     * Convert an approved purchase requisition into a draft Bill.
     * Items map 1-to-1 as bill line items (qty × unit_price).
     */
    public function convertRequisitionToBill(Requisition $requisition): Bill
    {
        if ($requisition->status !== RequisitionStatus::APPROVED) {
            throw new InvalidStatusTransitionException('Only approved requisitions can be converted.');
        }

        if ($requisition->type !== RequisitionType::PURCHASE) {
            throw new \InvalidArgumentException('convertRequisitionToBill requires a purchase-type requisition.');
        }

        return DB::transaction(function () use ($requisition): Bill {
            $bill = Bill::create([
                'vendor_id'  => $requisition->vendor_id,
                'bill_date'  => now()->toDateString(),
                'due_date'   => ($requisition->required_date ?? now()->addDays(30))->toDateString(),
                'subtotal'   => $requisition->total_amount,
                'tax_amount' => 0,
                'total'      => $requisition->total_amount,
                'currency'   => $requisition->currency,
                'notes'      => "Converted from requisition {$requisition->requisition_number}",
                'status'     => 'draft',
            ]);

            foreach ($requisition->items as $item) {
                $bill->items()->create([
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'total'       => $item->total,
                    'tax_amount'  => 0,
                ]);
            }

            $requisition->markConverted(Bill::class, $bill->id);

            return $bill->fresh('items');
        });
    }

    /**
     * Convert an approved expense requisition into a draft Expense.
     * Total amount is placed on the requisition's linked account.
     */
    public function convertRequisitionToExpense(Requisition $requisition): Expense
    {
        if ($requisition->status !== RequisitionStatus::APPROVED) {
            throw new InvalidStatusTransitionException('Only approved requisitions can be converted.');
        }

        if ($requisition->type !== RequisitionType::EXPENSE) {
            throw new \InvalidArgumentException('convertRequisitionToExpense requires an expense-type requisition.');
        }

        return DB::transaction(function () use ($requisition): Expense {
            $expense = Expense::create([
                'account_id'     => $requisition->account_id,
                'expense_date'   => now()->toDateString(),
                'subtotal'       => $requisition->total_amount,
                'tax_amount'     => 0,
                'total'          => $requisition->total_amount,
                'currency'       => $requisition->currency,
                'description'    => $requisition->title,
                'notes'          => "Converted from requisition {$requisition->requisition_number}",
                'payment_method' => 'credit',
                'status'         => 'draft',
            ]);

            foreach ($requisition->items as $item) {
                $expense->items()->create([
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'total'       => $item->total,
                ]);
            }

            $requisition->markConverted(Expense::class, $expense->id);

            return $expense->fresh('items');
        });
    }

    // -------------------------------------------------------------------------
    // Inventory Financing
    // -------------------------------------------------------------------------

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
        $principal = $facility->outstandingPrincipal();

        if ($principal <= 0) {
            return null;
        }

        $interest = round($principal * $facility->monthly_rate, 2);
        $date ??= now()->endOfMonth()->toDateString();
        $interestAcct = $this->requireAccount($this->accountCode('financing_interest'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => 'INT-' . now()->format('Y-m') . '-' . $facility->id,
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

    // -------------------------------------------------------------------------
    // Organizational Loans (term, working-capital, inter-company, director …)
    // -------------------------------------------------------------------------

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
    ): LoanFacility {
        return DB::transaction(function () use (
            $lenderName, $loanType, $loanTerm, $monthlyRate,
            $sbuCode, $loanAmount, $disbursedAt, $dueAt, $tenureMonths, $contact,
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
                'disbursed_at'         => $disbursedAt,
                'due_at'               => $dueAt,
                'tenure_months'        => $tenureMonths,
            ]);
        });
    }

    /**
     * Record a loan disbursement — funds received into Bank.
     *
     * DR Bank (1100) / CR Loan Payable (240x or 250x)
     * Journal entry is tagged with the facility's sbu_code.
     */
    public function drawdownLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
    ): JournalEntry {
        if (!$facility->is_active) {
            throw new \RuntimeException("Loan facility '{$facility->lender_name}' is inactive.");
        }

        $bank = $this->requireAccount($this->accountCode('bank'));
        $effectiveSbu = $sbuCode ?? $facility->sbu_code;

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'description' => $description ?? "Loan disbursement — {$facility->lender_name}",
            'sbu_code'    => $effectiveSbu,
            'lines'       => [
                ['account_id' => $bank->id,                          'type' => 'debit',  'amount' => $amount],
                ['account_id' => $facility->principal_account_id,    'type' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    /**
     * Accrue one month's interest for a single loan facility.
     *
     * DR Interest Expense 6720 (short) or 6730 (long) / CR Accrued Interest (242x or 252x)
     * Returns null when outstanding principal is zero.
     */
    public function accrueLoanInterest(
        LoanFacility $facility,
        mixed $date = null,
    ): ?JournalEntry {
        $principal = $facility->outstandingPrincipal();

        if ($principal <= 0) {
            return null;
        }

        $interest = round($principal * $facility->monthly_rate, 2);
        $date ??= now()->endOfMonth()->toDateString();
        $expenseCode = $facility->isShortTerm() ? '6720' : '6730';
        $expenseAcct = $this->requireAccount($expenseCode);

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => 'LOAN-INT-' . now()->format('Y-m') . '-' . $facility->id,
            'type'        => 'general',
            'sbu_code'    => $facility->sbu_code,
            'description' => sprintf(
                'Loan interest accrual — %s (%s) — %s — principal %s × %.2f%%/mo',
                $facility->lender_name,
                str_replace('_', ' ', $facility->loan_type),
                now()->format('F Y'),
                number_format($principal, 2),
                $facility->monthly_rate * 100,
            ),
            'lines' => [
                ['account_id' => $expenseAcct->id,               'type' => 'debit',  'amount' => $interest],
                ['account_id' => $facility->interest_account_id, 'type' => 'credit', 'amount' => $interest],
            ],
        ]);
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
     * DR Accrued Interest (242x or 252x) / CR Bank (1100)
     */
    public function payLoanInterest(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
    ): JournalEntry {
        $bank = $this->requireAccount($this->accountCode('bank'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'sbu_code'    => $facility->sbu_code,
            'description' => "Loan interest payment — {$facility->lender_name}",
            'lines'       => [
                ['account_id' => $facility->interest_account_id, 'type' => 'debit',  'amount' => $amount],
                ['account_id' => $bank->id,                      'type' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    /**
     * Repay principal to the lender.
     *
     * DR Loan Payable (240x or 250x) / CR Bank (1100)
     */
    public function repayLoan(
        LoanFacility $facility,
        float $amount,
        string $date,
        string $reference,
        ?string $description = null,
        ?string $sbuCode = null,
    ): JournalEntry {
        $outstanding = $facility->outstandingPrincipal();

        if ($amount > $outstanding + 0.01) {
            throw new \RuntimeException(
                "Repayment of {$amount} exceeds outstanding principal of {$outstanding} for '{$facility->lender_name}'.",
            );
        }

        $bank = $this->requireAccount($this->accountCode('bank'));
        $effectiveSbu = $sbuCode ?? $facility->sbu_code;

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'sbu_code'    => $effectiveSbu,
            'description' => $description ?? "Loan principal repayment — {$facility->lender_name}",
            'lines'       => [
                ['account_id' => $facility->principal_account_id, 'type' => 'debit',  'amount' => $amount],
                ['account_id' => $bank->id,                       'type' => 'credit', 'amount' => $amount],
            ],
        ]);
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
                'id'                    => $f->id,
                'lender_name'           => $f->lender_name,
                'loan_type'             => $f->loan_type,
                'loan_term'             => $f->loan_term,
                'sbu_code'              => $f->sbu_code,
                'is_active'             => $f->is_active,
                'monthly_rate'          => $f->monthly_rate,
                'loan_amount'           => $f->loan_amount,
                'disbursed_at'          => $f->disbursed_at?->toDateString(),
                'due_at'                => $f->due_at?->toDateString(),
                'months_remaining'      => $f->monthsRemaining(),
                'outstanding_principal' => $f->outstandingPrincipal(),
                'accrued_interest'      => $f->accruedInterest(),
                'monthly_interest'      => $f->monthlyInterestAmount(),
                'principal_account'     => $f->principalAccount?->code . ' ' . $f->principalAccount?->name,
                'interest_account'      => $f->interestAccount?->code . ' ' . $f->interestAccount?->name,
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Fixed Assets (Property, Plant & Equipment — IAS 16)
    // -------------------------------------------------------------------------

    /**
     * Register a fixed asset and auto-create its dedicated GL sub-accounts.
     *
     * Cost sub-accounts allocate under parent 1700 ("Fixed Assets"), range 1701–1799.
     * Accumulated-depreciation sub-accounts allocate under parent 1800, range 1801–1899.
     * Does not post a journal entry — see capitalizeFixedAsset() for that.
     */
    public function addFixedAsset(
        string $name,
        float $acquisitionCost,
        int $usefulLifeMonths,
        float $salvageValue = 0.0,
        ?string $acquiredAt = null,
        ?string $assetClass = null,
        ?string $sbuCode = null,
        ?string $location = null,
        ?string $serialNumber = null,
        ?string $notes = null,
    ): FixedAsset {
        return DB::transaction(function () use (
            $name, $acquisitionCost, $usefulLifeMonths, $salvageValue,
            $acquiredAt, $assetClass, $sbuCode, $location, $serialNumber, $notes,
        ): FixedAsset {
            $assetParent = $this->requireAccount('1700');
            $contraParent = $this->requireAccount('1800');

            $assetCode = $this->nextSubAccountCode('1700', '1799');
            $contraCode = $this->nextSubAccountCode('1800', '1899');

            $shortName = Str::limit($name, 40, '');

            $assetAccount = Account::create([
                'code'      => $assetCode,
                'name'      => $shortName,
                'type'      => 'asset',
                'subtype'   => 'fixed_asset',
                'parent_id' => $assetParent->id,
                'is_system' => false,
            ]);

            $contraAccount = Account::create([
                'code'      => $contraCode,
                'name'      => "Accum. Depr. — {$shortName}",
                'type'      => 'asset',
                'subtype'   => 'contra_account',
                'parent_id' => $contraParent->id,
                'is_system' => false,
            ]);

            return FixedAsset::create([
                'name'                                => $name,
                'asset_class'                         => $assetClass,
                'sbu_code'                            => $sbuCode ? strtoupper(trim($sbuCode)) : null,
                'asset_account_id'                    => $assetAccount->id,
                'accumulated_depreciation_account_id' => $contraAccount->id,
                'acquisition_cost'                    => $acquisitionCost,
                'salvage_value'                       => $salvageValue,
                'useful_life_months'                  => $usefulLifeMonths,
                'depreciation_method'                 => 'straight_line',
                'acquired_at'                         => $acquiredAt ?? now()->toDateString(),
                'location'                            => $location,
                'serial_number'                       => $serialNumber,
                'notes'                               => $notes,
                'is_active'                           => true,
                'created_by'                          => auth()->id(),
            ]);
        });
    }

    /**
     * Capitalize the asset: record the outlay against its GL cost account.
     *
     * DR asset account (its own 170x code) / CR payment source (bank by default,
     * or another account code such as accounts_payable for credit purchases).
     */
    public function capitalizeFixedAsset(
        FixedAsset $asset,
        string $date,
        string $reference,
        ?string $paymentAccountCode = null,
        ?string $description = null,
    ): JournalEntry {
        $paymentAccount = $this->requireAccount($paymentAccountCode ?? $this->accountCode('bank'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => $reference,
            'type'        => 'general',
            'sbu_code'    => $asset->sbu_code,
            'description' => $description ?? "Fixed asset capitalized — {$asset->name} ({$asset->asset_code})",
            'lines'       => [
                ['account_id' => $asset->asset_account_id, 'type' => 'debit',  'amount' => (float) $asset->acquisition_cost],
                ['account_id' => $paymentAccount->id,       'type' => 'credit', 'amount' => (float) $asset->acquisition_cost],
            ],
        ]);
    }

    /**
     * Post one period's straight-line depreciation for a single asset.
     *
     * DR Depreciation Expense (config: depreciation_expense, default 6600) / CR the
     * asset's own accumulated-depreciation account. Returns null when the asset is
     * inactive, already disposed, or fully depreciated. The final period is capped so
     * accumulated depreciation never exceeds the depreciable base.
     */
    public function depreciateAsset(FixedAsset $asset, ?string $date = null): ?JournalEntry
    {
        if (!$asset->is_active || $asset->isDisposed() || $asset->isFullyDepreciated()) {
            return null;
        }

        $remaining = round($asset->depreciableBase() - $asset->accumulatedDepreciation(), 2);
        $amount = min($asset->monthlyDepreciationAmount(), $remaining);

        if ($amount <= 0.0) {
            return null;
        }

        $date ??= now()->endOfMonth()->toDateString();
        $expenseAccount = $this->requireAccount($this->accountCode('depreciation_expense'));

        return $this->createJournalEntry([
            'date'        => $date,
            'reference'   => 'FA-DEPR-' . now()->format('Y-m') . '-' . $asset->id,
            'type'        => 'general',
            'sbu_code'    => $asset->sbu_code,
            'description' => sprintf(
                'Depreciation — %s (%s) — %s',
                $asset->name,
                $asset->asset_code,
                now()->format('F Y'),
            ),
            'lines' => [
                ['account_id' => $expenseAccount->id,                          'type' => 'debit',  'amount' => $amount],
                ['account_id' => $asset->accumulated_depreciation_account_id,  'type' => 'credit', 'amount' => $amount],
            ],
        ]);
    }

    /**
     * Depreciate all active, non-disposed assets.
     * Returns array keyed by asset id → JournalEntry|null.
     */
    public function depreciateAllAssets(?string $date = null): array
    {
        $results = [];

        FixedAsset::where('is_active', true)->whereNull('disposed_at')
            ->each(function (FixedAsset $asset) use ($date, &$results): void {
                $results[$asset->id] = $this->depreciateAsset($asset, $date);
            });

        return $results;
    }

    /**
     * Dispose of an asset: remove it and its accumulated depreciation from the GL,
     * record any cash proceeds, and plug the gain or loss on disposal.
     *
     * netBookValue = acquisition_cost − accumulated_depreciation
     * gainOrLoss   = proceeds − netBookValue   (positive = gain, negative = loss)
     *
     * Lines: CR asset account (acquisition_cost) / DR accumulated-depreciation account
     * (its balance, if any) / DR bank (proceeds, if any) / plug the gain (credit) or
     * loss (debit) to config('accounting.accounts.gain_loss_on_disposal') (default 4910).
     */
    public function disposeAsset(
        FixedAsset $asset,
        string $date,
        float $proceeds = 0.0,
        ?string $reference = null,
    ): JournalEntry {
        if ($asset->isDisposed()) {
            throw new \RuntimeException("Fixed asset '{$asset->asset_code}' has already been disposed.");
        }

        return DB::transaction(function () use ($asset, $date, $proceeds, $reference): JournalEntry {
            $accumulatedDepreciation = $asset->accumulatedDepreciation();
            $acquisitionCost = (float) $asset->acquisition_cost;
            $bookValue = round($acquisitionCost - $accumulatedDepreciation, 2);
            $gainOrLoss = round($proceeds - $bookValue, 2);

            $lines = [
                ['account_id' => $asset->asset_account_id, 'type' => 'credit', 'amount' => $acquisitionCost],
            ];

            if ($accumulatedDepreciation > 0) {
                $lines[] = ['account_id' => $asset->accumulated_depreciation_account_id, 'type' => 'debit', 'amount' => $accumulatedDepreciation];
            }

            if ($proceeds > 0) {
                $bank = $this->requireAccount($this->accountCode('bank'));
                $lines[] = ['account_id' => $bank->id, 'type' => 'debit', 'amount' => $proceeds];
            }

            if (abs($gainOrLoss) > 0.001) {
                $gainLossAccount = $this->requireAccount($this->accountCode('gain_loss_on_disposal'));
                $lines[] = $gainOrLoss > 0
                    ? ['account_id' => $gainLossAccount->id, 'type' => 'credit', 'amount' => $gainOrLoss]
                    : ['account_id' => $gainLossAccount->id, 'type' => 'debit',  'amount' => abs($gainOrLoss)];
            }

            $entry = $this->createJournalEntry([
                'date'        => $date,
                'reference'   => $reference ?? 'FA-DISPOSAL-' . now()->format('Y-m') . '-' . $asset->id,
                'type'        => 'general',
                'sbu_code'    => $asset->sbu_code,
                'description' => sprintf(
                    'Disposal of %s (%s) — book value %s, proceeds %s, %s %s',
                    $asset->name,
                    $asset->asset_code,
                    number_format($bookValue, 2),
                    number_format($proceeds, 2),
                    $gainOrLoss >= 0 ? 'gain' : 'loss',
                    number_format(abs($gainOrLoss), 2),
                ),
                'lines' => $lines,
            ]);

            $asset->update([
                'disposed_at'               => $date,
                'disposal_proceeds'         => $proceeds,
                'disposal_journal_entry_id' => $entry->id,
                'is_active'                 => false,
                'disposed_by'               => auth()->id(),
            ]);

            return $entry;
        });
    }

    /**
     * Fixed asset register: all assets with live GL-computed depreciation figures,
     * optionally filtered by SBU.
     */
    public function getFixedAssetRegister(?string $sbuCode = null): array
    {
        $query = FixedAsset::with(['assetAccount', 'accumulatedDepreciationAccount'])
            ->orderBy('asset_class')
            ->orderBy('name');

        if ($sbuCode !== null) {
            $query->where('sbu_code', strtoupper(trim($sbuCode)));
        }

        return $query->get()
            ->map(fn (FixedAsset $a): array => [
                'id'                               => $a->id,
                'asset_code'                       => $a->asset_code,
                'name'                             => $a->name,
                'asset_class'                      => $a->asset_class,
                'sbu_code'                         => $a->sbu_code,
                'is_active'                        => $a->is_active,
                'acquisition_cost'                 => (float) $a->acquisition_cost,
                'salvage_value'                    => (float) $a->salvage_value,
                'useful_life_months'               => $a->useful_life_months,
                'depreciation_method'              => $a->depreciation_method,
                'acquired_at'                      => $a->acquired_at?->toDateString(),
                'disposed_at'                      => $a->disposed_at?->toDateString(),
                'accumulated_depreciation'         => $a->accumulatedDepreciation(),
                'monthly_depreciation'             => $a->monthlyDepreciationAmount(),
                'net_book_value'                   => $a->netBookValue(),
                'is_fully_depreciated'             => $a->isFullyDepreciated(),
                'asset_account'                    => $a->assetAccount?->code . ' ' . $a->assetAccount?->name,
                'accumulated_depreciation_account' => $a->accumulatedDepreciationAccount?->code . ' ' . $a->accumulatedDepreciationAccount?->name,
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Get net income by type using the shared balance map. */
    // -------------------------------------------------------------------------
    // A/R & A/P Aging (QuickBooks-style buckets)
    // -------------------------------------------------------------------------

    /**
     * Accounts Receivable Aging Summary.
     *
     * Groups open/partially-settled invoices by customer into aging buckets:
     *   current (not yet due), 1–30, 31–60, 61–90, 91+ days past due.
     */
    public function getArAging(mixed $asOfDate = null, ?string $sbuCode = null): array
    {
        $asOf = $asOfDate ? \Illuminate\Support\Carbon::parse($asOfDate) : now();

        $invoices = Invoice::with('customer')
            ->whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])
            ->where('due_date', '<=', $asOf->toDateString())
            ->orWhere(fn ($q) => $q->whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue']))
            ->get();

        $rows = [];
        $totals = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

        foreach ($invoices->groupBy(fn ($inv) => (string) ($inv->customer?->name ?? 'Unknown')) as $name => $group) {
            $row = ['name' => $name, 'current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

            foreach ($group as $invoice) {
                $outstanding = (float) $invoice->total - (float) $invoice->paid_amount;

                if ($outstanding <= $this->tolerance()) {
                    continue;
                }

                $dueDate = \Illuminate\Support\Carbon::parse($invoice->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;

                $bucket = match (true) {
                    $daysOverdue === 0 => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $row[$bucket] += $outstanding;
                $row['total'] += $outstanding;
            }

            if ($row['total'] > $this->tolerance()) {
                $rows[] = $row;

                foreach (['current', '1_30', '31_60', '61_90', 'over_90', 'total'] as $b) {
                    $totals[$b] += $row[$b];
                }
            }
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'sbu_code'   => $this->normalizeSbuCode($sbuCode),
            'rows'       => $rows,
            'totals'     => $totals,
        ];
    }

    /**
     * Accounts Payable Aging Summary.
     *
     * Groups open/partially-settled bills by vendor into aging buckets:
     *   current (not yet due), 1–30, 31–60, 61–90, 91+ days past due.
     */
    public function getApAging(mixed $asOfDate = null, ?string $sbuCode = null): array
    {
        $asOf = $asOfDate ? \Illuminate\Support\Carbon::parse($asOfDate) : now();

        $bills = Bill::with('vendor')
            ->whereIn('status', ['issued', 'partially_settled', 'overdue'])
            ->get();

        $rows = [];
        $totals = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

        foreach ($bills->groupBy(fn ($bill) => (string) ($bill->vendor?->name ?? 'Unknown')) as $name => $group) {
            $row = ['name' => $name, 'current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];

            foreach ($group as $bill) {
                $outstanding = (float) $bill->total - (float) $bill->paid_amount;

                if ($outstanding <= $this->tolerance()) {
                    continue;
                }

                $dueDate = \Illuminate\Support\Carbon::parse($bill->due_date);
                $daysOverdue = $dueDate->isPast() ? (int) $dueDate->diffInDays($asOf) : 0;

                $bucket = match (true) {
                    $daysOverdue === 0 => 'current',
                    $daysOverdue <= 30 => '1_30',
                    $daysOverdue <= 60 => '31_60',
                    $daysOverdue <= 90 => '61_90',
                    default            => 'over_90',
                };

                $row[$bucket] += $outstanding;
                $row['total'] += $outstanding;
            }

            if ($row['total'] > $this->tolerance()) {
                $rows[] = $row;

                foreach (['current', '1_30', '31_60', '61_90', 'over_90', 'total'] as $b) {
                    $totals[$b] += $row[$b];
                }
            }
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'sbu_code'   => $this->normalizeSbuCode($sbuCode),
            'rows'       => $rows,
            'totals'     => $totals,
        ];
    }

    // -------------------------------------------------------------------------
    // Bank Reconciliation
    // -------------------------------------------------------------------------

    public function createBankReconciliation(array $data): BankReconciliation
    {
        return DB::transaction(fn (): BankReconciliation => BankReconciliation::create([
            'account_id'               => $data['account_id'],
            'statement_date'           => $data['statement_date'],
            'opening_balance'          => $data['opening_balance'] ?? 0,
            'statement_ending_balance' => $data['statement_ending_balance'] ?? 0,
            'status'                   => BankReconciliationStatus::DRAFT->value,
            'notes'                    => $data['notes'] ?? null,
        ]));
    }

    /**
     * Import already-parsed statement rows (CSV parsing happens in the Livewire layer).
     *
     * @param  array<int, array{transaction_date: string, description: string, amount: float,
     *               type: string, external_reference?: string|null}>  $rows
     */
    public function importBankStatementLines(BankReconciliation $reconciliation, array $rows): Collection
    {
        if ($reconciliation->status === BankReconciliationStatus::COMPLETED) {
            throw new AccountingException('Cannot import statement lines into a completed reconciliation.');
        }

        return DB::transaction(function () use ($reconciliation, $rows): Collection {
            $lines = new Collection();

            foreach ($rows as $row) {
                $lines->push(BankStatementLine::create([
                    'bank_reconciliation_id' => $reconciliation->id,
                    'transaction_date'       => $row['transaction_date'],
                    'description'            => $row['description'],
                    'amount'                 => $row['amount'],
                    'type'                   => strtolower((string) $row['type']),
                    'external_reference'     => $row['external_reference'] ?? null,
                ]));
            }

            return $lines;
        });
    }

    /** GL lines for an account that haven't yet been reconciled against a bank statement. */
    public function getUnreconciledLines(int $accountId): Collection
    {
        return Account::findOrFail($accountId)->journalEntryLines()
            ->whereNull('bank_reconciliation_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Match a statement line to a GL line: validates neither side is already matched,
     * the amounts agree within tolerance, and the debit/credit polarity matches exactly.
     */
    public function matchStatementLine(BankStatementLine $statementLine, JournalEntryLine $glLine): void
    {
        DB::transaction(function () use ($statementLine, $glLine): void {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);
            $glLine = JournalEntryLine::lockForUpdate()->findOrFail($glLine->id);

            if ($statementLine->matched_journal_entry_line_id !== null) {
                throw StatementLineAlreadyMatchedException::forLine($statementLine->id);
            }

            if ($glLine->bank_reconciliation_id !== null) {
                throw StatementLineAlreadyMatchedException::forGlLine($glLine->id);
            }

            $statementType = strtolower((string) $statementLine->type);
            $glType = strtolower((string) $glLine->type);

            if ($statementType !== $glType) {
                throw StatementLinePolarityMismatchException::make($statementType, $glType);
            }

            $variance = abs((float) $statementLine->amount - (float) $glLine->amount);

            if ($variance > $this->tolerance()) {
                throw AmountToleranceExceededException::make((float) $statementLine->amount, (float) $glLine->amount, $this->tolerance());
            }

            $now = now();

            $statementLine->update(['matched_journal_entry_line_id' => $glLine->id, 'matched_at' => $now]);
            $glLine->update(['bank_reconciliation_id' => $statementLine->bank_reconciliation_id, 'reconciled_at' => $now]);
        });
    }

    public function unmatchStatementLine(BankStatementLine $statementLine): void
    {
        DB::transaction(function () use ($statementLine): void {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);
            $reconciliation = BankReconciliation::findOrFail($statementLine->bank_reconciliation_id);

            if ($reconciliation->status === BankReconciliationStatus::COMPLETED) {
                throw new AccountingException('Cannot unmatch a line on a completed reconciliation.');
            }

            if ($statementLine->matched_journal_entry_line_id === null) {
                return;
            }

            $glLine = JournalEntryLine::lockForUpdate()->find($statementLine->matched_journal_entry_line_id);

            $statementLine->update(['matched_journal_entry_line_id' => null, 'matched_at' => null]);
            $glLine?->update(['bank_reconciliation_id' => null, 'reconciled_at' => null]);
        });
    }

    /**
     * Wraps createJournalEntry() with a bank-account leg + an offset leg (bank fees,
     * interest, etc.), posts it, then matches the bank leg to the unmatched statement
     * line in the same transaction — resolving lines that have no counterpart GL entry.
     */
    public function createAdjustingJournalEntryForStatementLine(BankStatementLine $statementLine, array $data): JournalEntry
    {
        return DB::transaction(function () use ($statementLine, $data): JournalEntry {
            $statementLine = BankStatementLine::lockForUpdate()->findOrFail($statementLine->id);

            if ($statementLine->matched_journal_entry_line_id !== null) {
                throw StatementLineAlreadyMatchedException::forLine($statementLine->id);
            }

            $reconciliation = BankReconciliation::findOrFail($statementLine->bank_reconciliation_id);
            $bankAccount = Account::findOrFail($reconciliation->account_id);
            $offsetAccount = $this->requireAccountById((int) $data['offset_account_id']);

            $type = strtolower((string) $statementLine->type);
            $amount = (float) $statementLine->amount;

            $entry = $this->createJournalEntry([
                'date'        => $statementLine->transaction_date,
                'reference'   => $statementLine->external_reference,
                'type'        => 'general',
                'description' => $data['description'] ?? "Bank reconciliation adjustment — {$statementLine->description}",
                'source_type' => BankReconciliation::class,
                'source_id'   => $reconciliation->id,
                'lines'       => [
                    ['account_id' => $bankAccount->id, 'type' => $type, 'amount' => $amount, 'description' => $statementLine->description],
                    ['account_id' => $offsetAccount->id, 'type' => $type === 'debit' ? 'credit' : 'debit', 'amount' => $amount, 'description' => $data['description'] ?? $statementLine->description],
                ],
            ]);

            $entry->post();

            $bankLine = $entry->lines()->where('account_id', $bankAccount->id)->first();
            $this->matchStatementLine($statementLine, $bankLine);

            return $entry;
        });
    }

    /**
     * Completes a reconciliation: every statement line must already be matched, and the
     * opening balance plus reconciled debits/credits must agree with the statement's
     * ending balance within tolerance.
     */
    public function completeBankReconciliation(BankReconciliation $reconciliation): void
    {
        DB::transaction(function () use ($reconciliation): void {
            $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliation->id);

            if ($reconciliation->statementLines()->whereNull('matched_journal_entry_line_id')->exists()) {
                throw new AccountingException('All statement lines must be matched or resolved via an adjusting entry before completing.');
            }

            $reconciledDebits = (float) $reconciliation->reconciledLines()->where('type', 'debit')->sum('amount');
            $reconciledCredits = (float) $reconciliation->reconciledLines()->where('type', 'credit')->sum('amount');

            $expected = round((float) $reconciliation->opening_balance + $reconciledDebits - $reconciledCredits, 2);
            $actual = round((float) $reconciliation->statement_ending_balance, 2);
            $variance = round(abs($expected - $actual), 2);

            if ($variance > $this->tolerance()) {
                throw ReconciliationBalanceMismatchException::make($expected, $actual, $variance);
            }

            $reconciliation->update([
                'status'        => BankReconciliationStatus::COMPLETED->value,
                'reconciled_by' => auth()->id(),
                'reconciled_at' => now(),
            ]);
        });
    }

    private function requireAccountById(int $accountId): Account
    {
        $account = Account::find($accountId);

        if ($account === null) {
            throw AccountNotFoundException::forCode((string) $accountId);
        }

        return $account;
    }

    protected function getNetIncome(mixed $startDate, mixed $endDate, ?string $sbuCode = null): float
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate, $sbuCode);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode);

        return (float) (($revenue['total'] ?? 0) - ($expenses['total'] ?? 0));
    }

    /**
     * Get accounts of a given type with their balances.
     * Uses the shared balance map — no per-account queries.
     *
     * @param  string[]  $onlySubtypes  restrict to these subtypes (empty = all)
     * @param  string[]  $excludeSubtypes  exclude these subtypes
     */
    protected function getAccountsByType(string $type, mixed $endDate, mixed $startDate = null, ?string $sbuCode = null, array $onlySubtypes = [], array $excludeSubtypes = []): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::where('type', $type)->where('is_active', true)
            ->when($onlySubtypes !== [], fn ($q) => $q->whereIn('subtype', $onlySubtypes))
            ->when($excludeSubtypes !== [], fn ($q) => $q->whereNotIn('subtype', $excludeSubtypes))
            ->orderBy('code')->get();
        $balanceMap = $this->buildBalanceMap($startDate, $endDate, $sbuCode);

        $accountsData = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            $row = $balanceMap->get($account->id);
            $debits = (float) ($row?->total_debit ?? 0);
            $credits = (float) ($row?->total_credit ?? 0);

            $balance = $account->isDebitAccount()
                ? ($debits - $credits)
                : ($credits - $debits);

            if (abs($balance) > $tolerance) {
                $accountsData[] = ['account' => $account, 'balance' => $balance];
                $total += $balance;
            }
        }

        return ['accounts' => $accountsData, 'total' => $total, 'sbu_code' => $this->normalizeSbuCode($sbuCode)];
    }
}
