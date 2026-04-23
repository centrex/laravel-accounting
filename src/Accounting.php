<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Centrex\Accounting\Enums\AccountSubtype;
use Centrex\Accounting\Exceptions\{
    AccountNotFoundException,
    DuplicatePaymentException,
    InvalidStatusTransitionException,
    OverpaymentException,
    UnbalancedJournalException
};
use Centrex\Accounting\Models\{
    Account,
    AccountBalance,
    Bill,
    Budget,
    BudgetItem,
    FiscalPeriod,
    FiscalYear,
    InventoryFinancingFacility,
    Invoice,
    JournalEntry,
    LoanFacility,
    Payment,
    PeriodInventorySnapshot
};
use Centrex\Accounting\Models\Expense;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        if (! is_array($meta)) {
            return null;
        }

        return $this->normalizeSbuCode(
            $meta['default_sbu'] ?? $meta['sbu_code'] ?? $meta['sbu'] ?? null,
        );
    }

    private function resolveModelSbuCode(mixed $model): ?string
    {
        if (! is_object($model)) {
            return null;
        }

        $meta = method_exists($model, 'getAttribute')
            ? $model->getAttribute('meta')
            : ($model->meta ?? null);

        return $this->extractSbuCodeFromMeta($meta);
    }

    private function resolveWarehouseSbuCode(?int $warehouseId): ?string
    {
        if ($warehouseId === null || ! class_exists('Centrex\\Inventory\\Models\\Warehouse')) {
            return null;
        }

        $warehouseClass = 'Centrex\\Inventory\\Models\\Warehouse';
        $warehouse = $warehouseClass::query()->find($warehouseId);

        return $this->resolveModelSbuCode($warehouse);
    }

    private function resolveInvoiceSbuCode(Invoice $invoice): ?string
    {
        $existingEntrySbu = $this->normalizeSbuCode($invoice->journalEntry?->sbu_code);

        if ($existingEntrySbu !== null) {
            return $existingEntrySbu;
        }

        if ($invoice->inventory_sale_order_id !== null && class_exists('Centrex\\Inventory\\Models\\SaleOrder')) {
            $saleOrderClass = 'Centrex\\Inventory\\Models\\SaleOrder';
            $saleOrder = $saleOrderClass::query()
                ->with(['warehouse', 'customer.modelable'])
                ->find($invoice->inventory_sale_order_id);

            $sbuCode = $this->resolveWarehouseSbuCode($saleOrder?->warehouse_id);
            $sbuCode ??= $this->resolveModelSbuCode($saleOrder?->customer?->modelable);
            $sbuCode ??= $this->resolveModelSbuCode($saleOrder?->customer);

            if ($sbuCode !== null) {
                return $sbuCode;
            }
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

        if ($bill->inventory_purchase_order_id !== null && class_exists('Centrex\\Inventory\\Models\\PurchaseOrder')) {
            $purchaseOrderClass = 'Centrex\\Inventory\\Models\\PurchaseOrder';
            $purchaseOrder = $purchaseOrderClass::query()
                ->with(['warehouse', 'supplier.modelable'])
                ->find($bill->inventory_purchase_order_id);

            $sbuCode = $this->resolveWarehouseSbuCode($purchaseOrder?->warehouse_id);
            $sbuCode ??= $this->resolveModelSbuCode($purchaseOrder?->supplier?->modelable);
            $sbuCode ??= $this->resolveModelSbuCode($purchaseOrder?->supplier);

            if ($sbuCode !== null) {
                return $sbuCode;
            }
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
     * @param  array{currency?: string, exchange_rate?: float, lines?: array<int, array{amount?: float|int|string}>} $data
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
        $end   = (int) $rangeEnd;

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
            ->groupBy('l.account_id')
        ;

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
                    if (! $usesGeneratedEntryNumber || ! $this->isDuplicateJournalEntryNumberException($exception)) {
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

        return $driverCode === 1062
            && str_contains($message, 'acct_journal_entries_entry_number_unique');
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
            $arAccount = $this->requireAccount('1200');
            $revenueAccount = $this->requireAccount('4000');
            $taxAccount = $this->requireAccount('2300');
            $discountAmount = round((float) ($invoice->discount_amount ?? 0), 2);
            $netRevenueAmount = round((float) $invoice->subtotal - $discountAmount, 2);

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
            $outstanding = round((float) $invoice->total - (float) $invoice->paid_amount, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
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
            $cashCode = $paymentData['account_code'] ?? '1000';
            $cashAccount = $this->requireAccount($cashCode);
            $arAccount = $this->requireAccount('1200');

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
                'currency'    => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->resolveInvoiceSbuCode($invoice),
                'lines'       => [
                    ['account_id' => $cashAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => 'Cash received'],
                    ['account_id' => $arAccount->id,   'type' => 'credit', 'amount' => $amount, 'description' => 'Accounts Receivable'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            // Atomic status update — compute from known locked value, no refresh needed
            $newPaid = round((float) $invoice->paid_amount + $amount, 6);
            $newStatus = $newPaid >= (float) $invoice->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $invoice->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });
    }

    // -------------------------------------------------------------------------
    // Bills
    // -------------------------------------------------------------------------

    /** Post a bill: DR Expense + Tax / CR Accounts Payable. */
    public function postBill(Bill $bill): JournalEntry
    {
        if ($bill->journal_entry_id !== null) {
            throw InvalidStatusTransitionException::make('Bill', $bill->status->value, 'posted');
        }

        return DB::transaction(function () use ($bill): JournalEntry {
            $apAccount = $this->requireAccount('2000');
            $expenseAccount = $this->requireAccount('5000');
            $taxAccount = $this->requireAccount('2300');

            $entry = $this->createJournalEntry([
                'date'          => $bill->bill_date,
                'reference'     => $bill->bill_number,
                'description'   => "Bill {$bill->bill_number} - {$bill->vendor?->name}",
                'currency'      => $bill->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $bill->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveBillSbuCode($bill),
                'lines'         => [
                    ['account_id' => $expenseAccount->id, 'type' => 'debit',  'amount' => $bill->subtotal,   'description' => 'Expense'],
                    ['account_id' => $taxAccount->id,     'type' => 'debit',  'amount' => $bill->tax_amount, 'description' => 'Tax'],
                    ['account_id' => $apAccount->id,      'type' => 'credit', 'amount' => $bill->total,      'description' => 'Accounts Payable'],
                ],
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

            $apAccount = $this->requireAccount('2000');
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? '1000');

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
                : $this->requireAccount('5000');

            $cashAccount = $this->requireAccount('1000');
            $payableAccount = $this->requireAccount('2000');
            $taxAccount = Account::where('code', '2300')->where('is_active', true)->first();
            $isCreditExpense = $expense->payment_method === 'credit';

            $lines = [
                ['account_id' => $expenseAccount->id, 'type' => 'debit', 'amount' => (float) $expense->subtotal, 'description' => 'Expense'],
            ];

            if ((float) $expense->tax_amount > 0 && $taxAccount !== null) {
                $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => (float) $expense->tax_amount, 'description' => 'Tax'];
            }

            $totalCredit = round((float) $expense->subtotal + (float) $expense->tax_amount, 6);
            $creditAccount = $isCreditExpense ? $payableAccount : $cashAccount;
            $creditDesc = $isCreditExpense ? 'Accounts Payable' : 'Cash paid';

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

            $payableAccount = $this->requireAccount('2000');
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? '1000');

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
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode);

        return [
            'period'       => ['start' => $startDate, 'end' => $endDate],
            'revenue'      => $revenue,
            'expenses'     => $expenses,
            'gross_profit' => $revenue['total'] ?? 0,
            'net_income'   => ($revenue['total'] ?? 0) - ($expenses['total'] ?? 0),
            'sbu_code'     => $this->normalizeSbuCode($sbuCode),
        ];
    }

    /**
     * Generate Cash Flow Statement.
     * Eager-loads journalEntry.lines.account to avoid N+1 per transaction.
     */
    public function getCashFlowStatement(mixed $startDate, mixed $endDate, ?string $sbuCode = null): array
    {
        $cashAccount = Account::where('code', '1000')->where('is_active', true)->first()
            ?? throw AccountNotFoundException::forCode('1000');

        // Eager-load lines + accounts — eliminates N queries in the loop below
        $transactions = $cashAccount->journalEntryLines()
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate, $sbuCode) {
                $q->where('status', 'posted')->whereBetween('date', [$startDate, $endDate]);
                $this->applySbuFilter($q, $sbuCode, $q->getModel()->getTable());
            })
            ->with(['journalEntry.lines.account'])
            ->get();

        $operating = 0.0;
        $investing = 0.0;
        $financing = 0.0;

        foreach ($transactions as $transaction) {
            $amount = $transaction->type === 'debit'
                ? (float) $transaction->amount
                : -(float) $transaction->amount;

            foreach ($transaction->journalEntry->lines as $line) {
                if ($line->id === $transaction->id) {
                    continue; // skip the cash line itself
                }

                $accountType = $line->account?->type;
                $accountSubtype = $line->account?->subtype;

                // Enum or string comparison — handle both
                $typeValue = $accountType instanceof \BackedEnum ? $accountType->value : (string) $accountType;
                $subtypeValue = $accountSubtype instanceof \BackedEnum ? $accountSubtype->value : (string) $accountSubtype;

                if (in_array($typeValue, ['revenue', 'expense'], true)) {
                    $operating += $amount;
                } elseif ($subtypeValue === AccountSubtype::FIXED_ASSET->value) {
                    $investing += $amount;
                } elseif (in_array($typeValue, ['liability', 'equity'], true)) {
                    $financing += $amount;
                }
            }
        }

        return [
            'period'               => ['start' => $startDate, 'end' => $endDate],
            'operating_activities' => $operating,
            'investing_activities' => $investing,
            'financing_activities' => $financing,
            'net_change'           => $operating + $investing + $financing,
            'sbu_code'             => $this->normalizeSbuCode($sbuCode),
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
                'period' => ['start' => $startDate, 'end' => $endDate],
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
                    SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit"
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
                    'line_id' => (int) $row->line_id,
                    'journal_entry_id' => (int) $row->journal_entry_id,
                    'entry_number' => $row->entry_number,
                    'date' => $row->date,
                    'reference' => $row->line_reference ?: $row->journal_reference,
                    'journal_type' => $row->journal_type,
                    'journal_description' => $row->journal_description,
                    'line_description' => $row->line_description,
                    'sbu_code' => $row->sbu_code,
                    'debit' => $debit,
                    'credit' => $credit,
                    'running_balance' => $runningBalance,
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
                'account' => $account,
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'period_debits' => $periodDebits,
                'period_credits' => $periodCredits,
                'entries' => $entries,
            ];
        }

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'accounts' => $ledgerAccounts,
            'sbu_code' => $this->normalizeSbuCode($sbuCode),
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
            ['code' => '5000', 'name' => 'Cost of Goods Sold',      'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
            ['code' => '6000', 'name' => 'Salaries & Wages',        'type' => 'expense',   'subtype' => 'salaries_and_wages_expense'],
            ['code' => '6100', 'name' => 'Rent Expense',            'type' => 'expense',   'subtype' => 'rent_expense'],
            ['code' => '6200', 'name' => 'Utilities',               'type' => 'expense',   'subtype' => 'utilities_expense'],
            ['code' => '6300', 'name' => 'Office Supplies',         'type' => 'expense',   'subtype' => 'office_supplies_expense'],
            ['code' => '6400', 'name' => 'Insurance',               'type' => 'expense',   'subtype' => 'insurance_expense'],
            ['code' => '6500', 'name' => 'Marketing & Advertising', 'type' => 'expense',   'subtype' => 'marketing_expense'],
            ['code' => '6600', 'name' => 'Depreciation',            'type' => 'expense',   'subtype' => 'depreciation_expense'],
            ['code' => '6700', 'name' => 'Interest Expense',        'type' => 'expense',   'subtype' => 'interest_expense'],
            ['code' => '6710', 'name' => 'Interest Expense — Inventory Financing', 'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6720', 'name' => 'Interest Expense — Short-term Loans',   'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6730', 'name' => 'Interest Expense — Long-term Loans',    'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6800', 'name' => 'Bank Fees',               'type' => 'expense',   'subtype' => 'bank_fees_expense'],
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
            $child  = Account::where('code', $childCode)->first();

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

            $retainedEarnings = $this->requireAccount('3100');

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
        return config('inventory.erp.accounting.enabled', false)
            && class_exists(\Centrex\Inventory\Models\WarehouseProduct::class);
    }

    /**
     * Snapshot WAC and qty_on_hand per warehouse+product at period-end and reconcile against GL.
     */
    private function takeInventoryPeriodSnapshot(FiscalPeriod $period): array
    {
        $currency = $this->baseCurrency();

        // Idempotent: remove any previous snapshot for this period before re-inserting
        PeriodInventorySnapshot::where('fiscal_period_id', $period->id)->delete();

        $warehouseProducts = \Centrex\Inventory\Models\WarehouseProduct::query()
            ->with(['warehouse:id,code,name', 'product:id,sku,name'])
            ->get();

        $rows = $warehouseProducts->map(fn ($wp) => [
            'fiscal_period_id' => $period->id,
            'warehouse_code'   => $wp->warehouse?->code,
            'warehouse_name'   => $wp->warehouse?->name,
            'product_sku'      => $wp->product?->sku,
            'product_name'     => $wp->product?->name,
            'qty_on_hand'      => (float) $wp->qty_on_hand,
            'wac_amount'       => (float) $wp->wac_amount,
            'total_value'      => round((float) $wp->qty_on_hand * (float) $wp->wac_amount, 2),
            'currency'         => $currency,
            'snapshot_date'    => $period->end_date,
            'created_at'       => now(),
            'updated_at'       => now(),
        ])->toArray();

        if ($rows !== []) {
            PeriodInventorySnapshot::insert($rows);
        }

        $physicalValue = (float) collect($rows)->sum('total_value');
        $inventoryAccountCode = config('inventory.erp.accounting.accounts.inventory_asset', '1300');
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
    // Inventory Financing
    // -------------------------------------------------------------------------

    /**
     * Register a new lender and auto-create its dedicated GL sub-accounts.
     *
     * Sub-accounts are allocated sequentially under parent 2150 (principal)
     * and 2170 (accrued interest). Supports up to 19 lenders per range.
     *
     * @param  string  $lenderName    Display name of the financing entity
     * @param  string  $lenderType    bank | private | ngo | mfi | other
     * @param  float   $monthlyRate   Interest rate per month (0.02 = 2%)
     * @param  float|null $creditLimit  Maximum draw-down allowed (informational)
     * @param  string|null $contact   Contact person / reference
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
            $interestParent  = $this->requireAccount('2170');

            // Next available code under each parent range
            $principalCode = $this->nextSubAccountCode('2150', '2169');
            $interestCode  = $this->nextSubAccountCode('2170', '2189');

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

        $inventory = $this->requireAccount('1300');

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

        $interest      = round($principal * $facility->monthly_rate, 2);
        $date          = $date ?? now()->endOfMonth()->toDateString();
        $interestAcct  = $this->requireAccount('6710');

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
        $bank = $this->requireAccount('1100');

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

        $bank = $this->requireAccount('1100');

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
                'id'                   => $f->id,
                'lender_name'          => $f->lender_name,
                'lender_type'          => $f->lender_type,
                'is_active'            => $f->is_active,
                'monthly_rate'         => $f->monthly_rate,
                'credit_limit'         => $f->credit_limit,
                'outstanding_principal' => $f->outstandingPrincipal(),
                'accrued_interest'     => $f->accruedInterest(),
                'monthly_interest'     => $f->monthlyInterestAmount(),
                'principal_account'    => $f->principalAccount?->code . ' ' . $f->principalAccount?->name,
                'interest_account'     => $f->interestAccount?->code . ' ' . $f->interestAccount?->name,
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
     * @param  string      $lenderName   Name of the lending entity
     * @param  string      $loanType     term_loan | working_capital | inter_company | director | equipment | overdraft | bridge
     * @param  string      $loanTerm     short_term | long_term
     * @param  float       $monthlyRate  Monthly interest rate (0.02 = 2%)
     * @param  string|null $sbuCode      SBU all journal entries for this facility will be tagged with
     * @param  float|null  $loanAmount   Sanctioned/approved loan amount (informational)
     * @param  string|null $disbursedAt  Date the loan was disbursed
     * @param  string|null $dueAt        Repayment due date
     * @param  int|null    $tenureMonths Tenure in months
     * @param  string|null $contact      Lender contact reference
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
            [$interestParentCode,  $interestRangeEnd]  = $isShort ? ['2420', '2439'] : ['2520', '2539'];

            $principalParent = $this->requireAccount($principalParentCode);
            $interestParent  = $this->requireAccount($interestParentCode);

            $principalCode = $this->nextSubAccountCode($principalParentCode, $principalRangeEnd);
            $interestCode  = $this->nextSubAccountCode($interestParentCode, $interestRangeEnd);

            $shortName   = Str::limit($lenderName, 28, '');
            $typeLabel   = str_replace('_', ' ', ucfirst($loanType));

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

        $bank = $this->requireAccount('1100');
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

        $interest      = round($principal * $facility->monthly_rate, 2);
        $date          = $date ?? now()->endOfMonth()->toDateString();
        $expenseCode   = $facility->isShortTerm() ? '6720' : '6730';
        $expenseAcct   = $this->requireAccount($expenseCode);

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
        $bank = $this->requireAccount('1100');

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

        $bank = $this->requireAccount('1100');
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
    // Private helpers
    // -------------------------------------------------------------------------

    /** Get net income by type using the shared balance map. */
    protected function getNetIncome(mixed $startDate, mixed $endDate, ?string $sbuCode = null): float
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate, $sbuCode);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate, $sbuCode);

        return (float) (($revenue['total'] ?? 0) - ($expenses['total'] ?? 0));
    }

    /**
     * Get accounts of a given type with their balances.
     * Uses the shared balance map — no per-account queries.
     */
    protected function getAccountsByType(string $type, mixed $endDate, mixed $startDate = null, ?string $sbuCode = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::where('type', $type)->where('is_active', true)->orderBy('code')->get();
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
