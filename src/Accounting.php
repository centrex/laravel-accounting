<?php

declare(strict_types = 1);

namespace Centrex\Accounting;

use Centrex\Accounting\Contracts\InventorySnapshotProvider;
use Centrex\Accounting\Enums\{BankReconciliationStatus, RequisitionStatus, RequisitionType};
use Centrex\Accounting\Exceptions\{
    AccountingException,
    AmountToleranceExceededException,
    InvalidStatusTransitionException,
    ReconciliationBalanceMismatchException,
    StatementLineAlreadyMatchedException,
    StatementLinePolarityMismatchException
};
use Centrex\Accounting\Models\{
    Account,
    AccountBalance,
    BankReconciliation,
    BankStatementLine,
    Bill,
    Budget,
    BudgetItem,
    FiscalPeriod,
    FiscalYear,
    FixedAsset,
    InventoryFinancingFacility,
    Invoice,
    JournalEntry,
    JournalEntryLine,
    LoanFacility,
    Owner,
    Payment,
    PeriodInventorySnapshot,
    Requisition,
    RequisitionItem
};
use Centrex\Accounting\Models\Expense;
use Centrex\Accounting\Support\DayRange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Gate};

class Accounting
{
    use Concerns\GeneratesFinancialReports;
    use Concerns\HasSharedAccountingHelpers;
    use Concerns\ManagesBills;
    use Concerns\ManagesCreditMemos;
    use Concerns\ManagesExpenses;
    use Concerns\ManagesInvoices;
    use Concerns\ManagesJournalEntries;

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
            ['code' => '1450', 'name' => 'Employee Loans & Advances Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
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
            ['code' => '5505', 'name' => 'Inventory Shrinkage & Write-offs', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '6000', 'name' => 'Salaries & Wages',        'type' => 'expense',   'subtype' => 'salaries_and_wages_expense'],
            ['code' => '6100', 'name' => 'Rent Expense',            'type' => 'expense',   'subtype' => 'rent_expense'],
            // Contra-revenue (IFRS 15 variable consideration), not operating expenses —
            // netted against Sales Revenue (4000) in getIncomeStatement() so Gross Profit
            // reflects Net Revenue - COGS. See AccountSubtype::CONTRA_REVENUE.
            ['code' => '6130', 'name' => 'Sales Discount',          'type' => 'revenue',   'subtype' => 'contra_revenue'],
            ['code' => '6131', 'name' => 'Early Payment Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6132', 'name' => 'Volume Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6133', 'name' => 'Promotional Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
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
            ->where('date', '>=', $period->start_date)
            ->where('date', '<=', $period->end_date)
            ->count();

        $openInvoices = $db->table("{$prefix}invoices")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->where('invoice_date', '>=', $period->start_date)
            ->where('invoice_date', '<=', $period->end_date)
            ->count();

        $openBills = $db->table("{$prefix}bills")
            ->whereNull('deleted_at')
            ->whereIn('status', ['draft', 'sent'])
            ->where('bill_date', '>=', $period->start_date)
            ->where('bill_date', '<=', $period->end_date)
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
            ->when($asOfDate, fn ($q) => $q->where('je.date', '<', DayRange::endOfDay($asOfDate)))
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
            ->where('je.date', '>=', $period->start_date)
            ->where('je.date', '<=', $period->end_date)
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
            ->where('period_start', '<=', $endDate)
            ->where('period_end', '>=', $startDate)
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
        $principalLocal = $facility->outstandingPrincipalLocal();

        if ($principalLocal <= 0) {
            return null;
        }

        $interestLocal = round($principalLocal * $facility->monthly_rate, 2);
        $date ??= now()->endOfMonth()->toDateString();
        $expenseCode = $facility->isShortTerm() ? '6720' : '6730';
        $expenseAcct = $this->requireAccount($expenseCode);

        return $this->createJournalEntry([
            'date'          => $date,
            'reference'     => 'LOAN-INT-' . now()->format('Y-m') . '-' . $facility->id,
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

    // -------------------------------------------------------------------------
    // Owner's Equity (multi-owner)
    // -------------------------------------------------------------------------

    /**
     * Register an owner/partner and auto-create their dedicated Capital and Drawings GL
     * sub-accounts under the standard aggregate 3000/3200 accounts, so per-owner detail rolls
     * up into the existing Balance Sheet equity total automatically (both sub-accounts carry
     * type=equity, same as their parent).
     *
     * Capital sub-accounts allocate under parent 3000, range 3001–3099.
     * Drawings sub-accounts allocate under parent 3200, range 3201–3299.
     *
     * @param  array{code: string, name: string, email?: ?string, ownership_percentage?: ?float, notes?: ?string, is_active?: bool}  $data
     */
    public function addOwner(array $data): Owner
    {
        return DB::transaction(function () use ($data): Owner {
            $capitalParent = $this->requireAccount(config('accounting.accounts.capital', '3000'));
            $drawingsParent = $this->requireAccount(config('accounting.accounts.owner_drawings', '3200'));

            $capitalCode = $this->nextSubAccountCode($capitalParent->code, '3099');
            $drawingsCode = $this->nextSubAccountCode($drawingsParent->code, '3299');

            $shortName = Str::limit($data['name'], 30, '');

            $capitalAccount = Account::create([
                'code'      => $capitalCode,
                'name'      => "Capital — {$shortName}",
                'type'      => 'equity',
                'subtype'   => 'capital_account',
                'parent_id' => $capitalParent->id,
                'is_system' => false,
            ]);

            $drawingsAccount = Account::create([
                'code'      => $drawingsCode,
                'name'      => "Drawings — {$shortName}",
                'type'      => 'equity',
                'subtype'   => 'drawings_account',
                'parent_id' => $drawingsParent->id,
                'is_system' => false,
            ]);

            return Owner::create([
                'code'                 => $data['code'],
                'name'                 => $data['name'],
                'email'                => $data['email'] ?? null,
                'ownership_percentage' => $data['ownership_percentage'] ?? null,
                'capital_account_id'   => $capitalAccount->id,
                'drawings_account_id'  => $drawingsAccount->id,
                'notes'                => $data['notes'] ?? null,
                'is_active'            => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Record a capital contribution from a specific owner — DR the deposit account / CR that
     * owner's own Capital sub-account (see addOwner()), instead of the aggregate 3000 account.
     *
     * A foreign owner may contribute in their own currency: pass `currency`/`exchange_rate`
     * and `amount` is treated as that currency, converted to the accounting base currency for
     * the journal entry (same handling as Invoice/Bill/LoanFacility). Unlike a loan, equity has
     * no running balance to reconcile against, so this is a one-off per-transaction conversion —
     * there's nothing persisted on Owner itself.
     *
     * @param  array{amount: float, date?: string, deposit_account_code?: string, description?: ?string, currency?: ?string, exchange_rate?: float|int|string|null}  $data
     */
    public function recordOwnerContribution(Owner $owner, array $data): JournalEntry
    {
        $depositAccount = $this->requireAccount($data['deposit_account_code'] ?? config('accounting.accounts.bank', '1100'));

        $entry = $this->createJournalEntry([
            'date'          => $data['date'] ?? now()->toDateString(),
            'reference'     => 'CAP-' . $owner->code . '-' . now()->format('YmdHis'),
            'type'          => 'general',
            'description'   => $data['description'] ?? "Capital contribution — {$owner->name}",
            'currency'      => $data['currency'] ?? $this->baseCurrency(),
            'exchange_rate' => $this->normalizeExchangeRate($data['exchange_rate'] ?? 1),
            'lines'         => [
                ['account_id' => $depositAccount->id, 'type' => 'debit', 'amount' => (float) $data['amount']],
                ['account_id' => $owner->capital_account_id, 'type' => 'credit', 'amount' => (float) $data['amount']],
            ],
        ]);
        $entry->post();

        return $entry;
    }

    /**
     * Record a drawing/withdrawal for a specific owner — DR that owner's own Drawings
     * sub-account / CR the source account, instead of the aggregate 3200 account.
     *
     * A foreign owner may draw in their own currency: pass `currency`/`exchange_rate` and
     * `amount` is treated as that currency, converted to the accounting base currency for the
     * journal entry (same handling as recordOwnerContribution()).
     *
     * @param  array{amount: float, date?: string, source_account_code?: string, description?: ?string, currency?: ?string, exchange_rate?: float|int|string|null}  $data
     */
    public function recordOwnerDrawing(Owner $owner, array $data): JournalEntry
    {
        $sourceAccount = $this->requireAccount($data['source_account_code'] ?? config('accounting.accounts.bank', '1100'));

        $entry = $this->createJournalEntry([
            'date'          => $data['date'] ?? now()->toDateString(),
            'reference'     => 'DRAW-' . $owner->code . '-' . now()->format('YmdHis'),
            'type'          => 'general',
            'description'   => $data['description'] ?? "Owner drawing — {$owner->name}",
            'currency'      => $data['currency'] ?? $this->baseCurrency(),
            'exchange_rate' => $this->normalizeExchangeRate($data['exchange_rate'] ?? 1),
            'lines'         => [
                ['account_id' => $owner->drawings_account_id, 'type' => 'debit', 'amount' => (float) $data['amount']],
                ['account_id' => $sourceAccount->id, 'type' => 'credit', 'amount' => (float) $data['amount']],
            ],
        ]);
        $entry->post();

        return $entry;
    }

    /**
     * Per-owner capital/drawings/net-equity breakdown for reporting — the "By Owner" table
     * on the Owner's Equity page.
     *
     * @return Collection<int, array{owner: Owner, capital_balance: float, drawings_balance: float, net_equity: float}>
     */
    public function getOwnerEquitySummary(): Collection
    {
        return Owner::query()
            ->where('is_active', true)
            ->with(['capitalAccount', 'drawingsAccount'])
            ->orderBy('name')
            ->get()
            ->map(fn (Owner $owner): array => [
                'owner'            => $owner,
                'capital_balance'  => $owner->capitalAccount->getCurrentBalance(),
                'drawings_balance' => $owner->drawingsAccount->getCurrentBalance(),
                'net_equity'       => $owner->equityBalance(),
            ]);
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
}
