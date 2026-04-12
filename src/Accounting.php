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
    Bill,
    Budget,
    BudgetItem,
    FiscalYear,
    Invoice,
    JournalEntry,
    Payment
};
use Centrex\Inventory\Models\Expense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** Resolve a required account by code or throw a typed exception. */
    private function requireAccount(string $code): Account
    {
        return Account::where('code', $code)->where('is_active', true)->first()
            ?? throw AccountNotFoundException::forCode($code);
    }

    /**
     * Single aggregated query for journal-entry-line balances.
     * Replaces the N+1 pattern (3 queries per account) in every report method.
     *
     * Returns a Collection keyed by account_id, each item having
     * `total_debit` and `total_credit` properties.
     */
    private function buildBalanceMap(mixed $startDate, mixed $endDate): Collection
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        return DB::connection($connection)
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
            ->get()
            ->keyBy('account_id');
    }

    // -------------------------------------------------------------------------
    // Journal Entries
    // -------------------------------------------------------------------------

    /**
     * Create a balanced journal entry with lines.
     *
     * @param  array{date: string, reference?: string, type?: string, description?: string,
     *               currency?: string, exchange_rate?: float, lines: list<array{account_id: int,
     *               type: string, amount: float, description?: string, reference?: string}>} $data
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data): JournalEntry {
            $entryNumber = $data['entry_number']
                ?? ('JE-' . now()->format('YmdHis') . '-' . random_int(1000, 9999));

            $entry = JournalEntry::create([
                'entry_number'  => $entryNumber,
                'date'          => $data['date'],
                'reference'     => $data['reference'] ?? null,
                'type'          => $data['type'] ?? 'general',
                'description'   => $data['description'] ?? null,
                'currency'      => $data['currency'] ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                'created_by'    => $data['created_by'] ?? auth()->id(),
                'status'        => $data['status'] ?? 'draft',
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
        });
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

            $entry = $this->createJournalEntry([
                'date'          => $invoice->invoice_date,
                'reference'     => $invoice->invoice_number,
                'type'          => 'general',
                'description'   => "Invoice {$invoice->invoice_number} - {$invoice->customer?->name}",
                'currency'      => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $invoice->exchange_rate ?? 1.0,
                'lines'         => [
                    ['account_id' => $arAccount->id,      'type' => 'debit',  'amount' => $invoice->total,      'description' => 'Accounts Receivable'],
                    ['account_id' => $revenueAccount->id, 'type' => 'credit', 'amount' => $invoice->subtotal,   'description' => 'Sales Revenue'],
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
    public function getTrialBalance(mixed $startDate = null, mixed $endDate = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::where('is_active', true)->orderBy('code')->get();
        $balanceMap = $this->buildBalanceMap($startDate, $endDate);

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
        ];
    }

    /** Generate Balance Sheet (point-in-time). */
    public function getBalanceSheet(mixed $date = null): array
    {
        $date ??= now();

        $assets = $this->getAccountsByType('asset', $date);
        $liabilities = $this->getAccountsByType('liability', $date);
        $equity = $this->getAccountsByType('equity', $date);

        $netIncome = $this->getNetIncome(null, $date);
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
            'is_balanced' => abs(
                ($assets['total'] ?? 0) - (($liabilities['total'] ?? 0) + $retainedEarnings),
            ) < ($this->tolerance() * 2),
        ];
    }

    /** Generate Income Statement (P&L). */
    public function getIncomeStatement(mixed $startDate, mixed $endDate): array
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate);

        return [
            'period'       => ['start' => $startDate, 'end' => $endDate],
            'revenue'      => $revenue,
            'expenses'     => $expenses,
            'gross_profit' => $revenue['total'] ?? 0,
            'net_income'   => ($revenue['total'] ?? 0) - ($expenses['total'] ?? 0),
        ];
    }

    /**
     * Generate Cash Flow Statement.
     * Eager-loads journalEntry.lines.account to avoid N+1 per transaction.
     */
    public function getCashFlowStatement(mixed $startDate, mixed $endDate): array
    {
        $cashAccount = Account::where('code', '1000')->where('is_active', true)->first()
            ?? throw AccountNotFoundException::forCode('1000');

        // Eager-load lines + accounts — eliminates N queries in the loop below
        $transactions = $cashAccount->journalEntryLines()
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->whereBetween('date', [$startDate, $endDate]))
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
            ['code' => '2200', 'name' => 'Accrued Expenses',        'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',       'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Debt',          'type' => 'liability', 'subtype' => 'long_term_liability'],
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
            ['code' => '6800', 'name' => 'Bank Fees',               'type' => 'expense',   'subtype' => 'bank_fees_expense'],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['code' => $accountData['code']],
                array_merge($accountData, ['is_system' => true]),
            );
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

                $entry->post();
            }

            $fiscalYear->update(['is_closed' => true]);
        });
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
    // Private helpers
    // -------------------------------------------------------------------------

    /** Get net income by type using the shared balance map. */
    protected function getNetIncome(mixed $startDate, mixed $endDate): float
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate);

        return (float) (($revenue['total'] ?? 0) - ($expenses['total'] ?? 0));
    }

    /**
     * Get accounts of a given type with their balances.
     * Uses the shared balance map — no per-account queries.
     */
    protected function getAccountsByType(string $type, mixed $endDate, mixed $startDate = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = Account::where('type', $type)->where('is_active', true)->orderBy('code')->get();
        $balanceMap = $this->buildBalanceMap($startDate, $endDate);

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

        return ['accounts' => $accountsData, 'total' => $total];
    }
}
