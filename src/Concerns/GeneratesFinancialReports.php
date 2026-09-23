<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Enums\EntryStatus;
use Centrex\Accounting\Models\{Account, Bill, BillItem, Expense, Invoice, InvoiceItem, Payment, TaxRate};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\{Carbon, Collection};
use Illuminate\Support\Facades\DB;

/**
 * Every report here is ultimately one aggregate — HasSharedAccountingHelpers'
 * buildBalanceMap()/getAccountsByType(), memoised per report call via withReportMemo() —
 * sliced into the shape a trial balance, balance sheet, P&L, cash flow statement/forecast,
 * general ledger, cash book, sales tax liability, or aging report each need.
 */
trait GeneratesFinancialReports
{
    /** Generate Trial Balance. Uses a single aggregated SQL query instead of N+1. */
    public function getTrialBalance(mixed $startDate = null, mixed $endDate = null, ?string $sbuCode = null): array
    {
        $tolerance = $this->tolerance();
        $accounts = $this->rememberForReport(
            'accounts:all-active',
            fn (): Collection => Account::where('is_active', true)->orderBy('code')->get(),
        );
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

        return $this->withReportMemo(function () use ($date, $sbuCode): array {
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
                    'total_with_income' => $retainedEarnings,
                ]),
                'sbu_code'    => $this->normalizeSbuCode($sbuCode),
                'is_balanced' => abs(
                    ($assets['total'] ?? 0) - (($liabilities['total'] ?? 0) + $retainedEarnings),
                ) < ($this->tolerance() * 2),
            ];
        });
    }

    /** Generate Income Statement (P&L). */
    public function getIncomeStatement(mixed $startDate, mixed $endDate, ?string $sbuCode = null): array
    {
        return $this->withReportMemo(function () use ($startDate, $endDate, $sbuCode): array {
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
        });
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
        // Two balance sheets plus an income statement would otherwise re-run the same
        // journal-line aggregate thirteen times; one shared memo scope makes it two
        // (opening period and closing period).
        return $this->withReportMemo(
            fn (): array => $this->computeCashFlowStatement($startDate, $endDate, $sbuCode),
        );
    }

    /** @return array<string, mixed> */
    private function computeCashFlowStatement(mixed $startDate, mixed $endDate, ?string $sbuCode): array
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

        $openingCash = $this->cashBalanceAsOf($start->copy()->subDay(), $sbuCode);
        $closingCash = $this->cashBalanceAsOf($end, $sbuCode);

        return [
            'period'               => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'operating_activities' => round($operatingTotal, 2),
            'investing_activities' => round($investingActivities, 2),
            'financing_activities' => round($financingActivities, 2),
            'net_change'           => round($netChange, 2),
            'opening_cash_balance' => round($openingCash, 2),
            'closing_cash_balance' => round($closingCash, 2),
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
     * Cash Flow Forecast — projects future cash position over a rolling weekly horizon.
     *
     * Combines two sources:
     *  - Known items: due dates on open (unpaid/partially-paid) invoices and bills,
     *    bucketed by week. Overdue items are pulled into "This week" but their total
     *    is also reported separately under `overdue`, so collection risk stays visible
     *    instead of blending into a normal week.
     *  - A baseline "other operating cash flow" run-rate: the trailing average of
     *    posted cash/bank movements over `$lookbackDays` that are NOT invoice/bill
     *    payments (payroll, rent, direct cash sales, misc expenses, etc.) — approximates
     *    the recurring cash flow that has no due-dated document behind it.
     *
     * This is a projection based on current data, not a guarantee — it assumes overdue
     * and near-term items are eventually collected/paid and that recent recurring cash
     * flow continues at the same rate.
     */
    public function getCashFlowForecast(mixed $asOfDate = null, int $forecastWeeks = 12, int $lookbackDays = 90, ?string $sbuCode = null): array
    {
        $asOf = ($asOfDate ? Carbon::parse($asOfDate) : now())->startOfDay();
        $sbuCode = $this->normalizeSbuCode($sbuCode);
        $horizonEnd = $asOf->copy()->addWeeks($forecastWeeks)->subDay();

        $startingCash = $this->cashBalanceAsOf($asOf, $sbuCode);
        $runRate = $this->cashRunRate($asOf, $lookbackDays, $sbuCode);

        $buckets = [];

        for ($w = 0; $w < $forecastWeeks; $w++) {
            $bucketStart = $asOf->copy()->addDays($w * 7);
            $buckets[] = [
                'label'             => $w === 0 ? 'This week' : 'Week of ' . $bucketStart->format('M j'),
                'start'             => $bucketStart->toDateString(),
                'end'               => $bucketStart->copy()->addDays(6)->toDateString(),
                'expected_inflows'  => 0.0,
                'expected_outflows' => 0.0,
                'baseline_other'    => round($runRate['weekly'], 2),
            ];
        }

        $classify = function (Carbon $dueDate, float $amount, bool $isInflow) use ($asOf, $horizonEnd, $forecastWeeks, &$buckets): string {
            if ($dueDate->lt($asOf)) {
                $index = 0;
            } elseif ($dueDate->gt($horizonEnd)) {
                return 'beyond_horizon';
            } else {
                $index = min((int) floor($asOf->diffInDays($dueDate) / 7), $forecastWeeks - 1);
            }

            if ($isInflow) {
                $buckets[$index]['expected_inflows'] = round((float) $buckets[$index]['expected_inflows'] + $amount, 2);
            } else {
                $buckets[$index]['expected_outflows'] = round((float) $buckets[$index]['expected_outflows'] + $amount, 2);
            }

            return $index === 0 && $dueDate->lt($asOf) ? 'overdue' : (string) $buckets[$index]['label'];
        };

        $overdueAr = 0.0;
        $overdueAp = 0.0;
        $overdueExpense = 0.0;
        $beyondHorizonAr = 0.0;
        $beyondHorizonAp = 0.0;
        $beyondHorizonExpense = 0.0;

        $arSchedule = Invoice::query()
            ->whereNotIn('status', [EntryStatus::DRAFT->value, EntryStatus::VOID->value])
            ->when($sbuCode !== null, fn ($q) => $q->where('sbu_code', $sbuCode))
            ->with('customer')
            ->get()
            ->map(function (Invoice $invoice) use ($classify, &$overdueAr, &$beyondHorizonAr): ?array {
                $amount = round($invoice->base_balance, 2);

                if ($amount <= $this->tolerance()) {
                    return null;
                }

                $dueDate = Carbon::parse($invoice->due_date ?? $invoice->invoice_date);
                $bucket = $classify($dueDate, $amount, true);

                if ($bucket === 'overdue') {
                    $overdueAr += $amount;
                } elseif ($bucket === 'beyond_horizon') {
                    $beyondHorizonAr += $amount;
                }

                return [
                    'invoice_number' => $invoice->invoice_number,
                    'customer'       => $invoice->customer?->name,
                    'due_date'       => $dueDate->toDateString(),
                    'amount'         => $amount,
                    'bucket'         => $bucket,
                ];
            })
            ->filter()
            ->sortBy('due_date')
            ->values();

        $apSchedule = Bill::query()
            ->whereNotIn('status', [EntryStatus::DRAFT->value, EntryStatus::VOID->value])
            ->when($sbuCode !== null, fn ($q) => $q->where('sbu_code', $sbuCode))
            ->with('vendor')
            ->get()
            ->map(function (Bill $bill) use ($classify, &$overdueAp, &$beyondHorizonAp): ?array {
                $amount = round($bill->base_balance, 2);

                if ($amount <= $this->tolerance()) {
                    return null;
                }

                $dueDate = Carbon::parse($bill->due_date ?? $bill->bill_date);
                $bucket = $classify($dueDate, $amount, false);

                if ($bucket === 'overdue') {
                    $overdueAp += $amount;
                } elseif ($bucket === 'beyond_horizon') {
                    $beyondHorizonAp += $amount;
                }

                return [
                    'bill_number' => $bill->bill_number,
                    'vendor'      => $bill->vendor?->name,
                    'due_date'    => $dueDate->toDateString(),
                    'amount'      => $amount,
                    'bucket'      => $bucket,
                ];
            })
            ->filter()
            ->sortBy('due_date')
            ->values();

        // Approved credit expenses (payables not tied to a Bill) due within the window
        $expenseSchedule = Expense::query()
            ->where('status', 'approved')
            ->where('payment_method', 'credit')
            ->get()
            ->map(function (Expense $expense) use ($classify, &$overdueExpense, &$beyondHorizonExpense): ?array {
                $amount = round($expense->balance, 2);

                if ($amount <= $this->tolerance()) {
                    return null;
                }

                $dueDate = Carbon::parse($expense->due_date ?? $expense->expense_date);
                $bucket = $classify($dueDate, $amount, false);

                if ($bucket === 'overdue') {
                    $overdueExpense += $amount;
                } elseif ($bucket === 'beyond_horizon') {
                    $beyondHorizonExpense += $amount;
                }

                return [
                    'expense_number' => $expense->expense_number,
                    'vendor'         => $expense->vendor_name,
                    'due_date'       => $dueDate->toDateString(),
                    'amount'         => $amount,
                    'bucket'         => $bucket,
                ];
            })
            ->filter()
            ->sortBy('due_date')
            ->values();

        $running = round($startingCash, 2);

        foreach ($buckets as &$bucket) {
            $bucket['net'] = round($bucket['expected_inflows'] - $bucket['expected_outflows'] + $bucket['baseline_other'], 2);
            $running = round($running + $bucket['net'], 2);
            $bucket['projected_balance'] = $running;
        }
        unset($bucket);

        return [
            'as_of'                    => $asOf->toDateString(),
            'horizon_end'              => $horizonEnd->toDateString(),
            'forecast_weeks'           => $forecastWeeks,
            'starting_cash_balance'    => round($startingCash, 2),
            'ending_projected_balance' => $running,
            'run_rate'                 => $runRate,
            'overdue'                  => ['ar' => round($overdueAr, 2), 'ap' => round($overdueAp, 2), 'expenses' => round($overdueExpense, 2)],
            'beyond_horizon'           => ['ar' => round($beyondHorizonAr, 2), 'ap' => round($beyondHorizonAp, 2), 'expenses' => round($beyondHorizonExpense, 2)],
            'buckets'                  => $buckets,
            'ar_schedule'              => $arSchedule->all(),
            'ap_schedule'              => $apSchedule->all(),
            'expense_schedule'         => $expenseSchedule->all(),
            'sbu_code'                 => $sbuCode,
        ];
    }

    /** Sum of cash/bank account balances (codes 1000–1199) as of a given date (inclusive), from posted JE lines. */
    private function cashBalanceAsOf(Carbon $date, ?string $sbuCode): float
    {
        $accountIds = Account::query()
            ->where('is_active', true)
            ->whereBetween('code', ['1000', '1199'])
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return 0.0;
        }

        $prefix = config('accounting.table_prefix', 'acct_');

        $query = DB::table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('l.account_id', $accountIds->all())
            ->where('je.date', '<', DayRange::endOfDay($date->toDateString()))
            ->selectRaw(
                "SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE 0 END) as total_debit,
                SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit",
            );

        $row = $this->applySbuFilter($query, $sbuCode)->first();

        return (float) ($row?->total_debit ?? 0) - (float) ($row?->total_credit ?? 0);
    }

    /**
     * Trailing average of "other" operating cash flow — posted cash/bank movements
     * over the lookback window that are NOT invoice/bill payments (payroll, rent,
     * direct cash sales, misc expenses, etc.). Invoice/bill collections are already
     * projected explicitly from due dates elsewhere, so excluding them here avoids
     * double-counting them in the forecast.
     *
     * @return array{lookback_days: int, daily: float, weekly: float, basis: string}
     */
    private function cashRunRate(Carbon $asOf, int $lookbackDays, ?string $sbuCode): array
    {
        $lookbackStart = $asOf->copy()->subDays($lookbackDays);

        $accountIds = Account::query()
            ->where('is_active', true)
            ->whereBetween('code', ['1000', '1199'])
            ->pluck('id');

        $netCashMovement = 0.0;

        if ($accountIds->isNotEmpty()) {
            $prefix = config('accounting.table_prefix', 'acct_');

            $query = DB::table("{$prefix}journal_entry_lines as l")
                ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
                ->where('je.status', 'posted')
                ->whereIn('l.account_id', $accountIds->all())
                ->where('je.date', '>=', $lookbackStart->toDateString())
                ->where('je.date', '<', $asOf->toDateString())
                ->selectRaw(
                    "SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE 0 END) as total_debit,
                    SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit",
                );

            $row = $this->applySbuFilter($query, $sbuCode)->first();
            $netCashMovement = (float) ($row?->total_debit ?? 0) - (float) ($row?->total_credit ?? 0);
        }

        $paymentsQuery = Payment::query()
            ->whereIn('payable_type', [Invoice::class, Bill::class])
            ->where('payment_date', '>=', $lookbackStart->toDateString())
            ->where('payment_date', '<', $asOf->toDateString());

        if ($sbuCode !== null) {
            $paymentsQuery->whereHas('journalEntry', fn ($q) => $q->where('sbu_code', $sbuCode));
        }

        // Only real cash/bank movements here — Payment::scopeCashMovement() is the single
        // place that knows which payment_method values (e.g. 'credit_memo') settle a payable
        // without moving cash, so this stays correct as new non-cash methods are added.
        $invoicePayments = (float) (clone $paymentsQuery)->where('payable_type', Invoice::class)->cashMovement()->sum('amount');
        $billPayments = (float) (clone $paymentsQuery)->where('payable_type', Bill::class)->cashMovement()->sum('amount');

        $otherNetCashFlow = $netCashMovement - ($invoicePayments - $billPayments);
        $daily = $lookbackDays > 0 ? $otherNetCashFlow / $lookbackDays : 0.0;

        return [
            'lookback_days' => $lookbackDays,
            'daily'         => round($daily, 2),
            'weekly'        => round($daily * 7, 2),
            'basis'         => "Trailing {$lookbackDays}-day average of cash movements not tied to invoice/bill collections (e.g. payroll, rent, direct cash sales, misc expenses).",
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
                ->where('je.date', '<', $startDate)
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
            ->when($startDate !== null, fn ($q) => $q->where('je.date', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('je.date', '<', DayRange::endOfDay($endDate)))
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
     * Cash Book: a single chronological receipts/payments ledger across every
     * cash/bank account (codes 1000–1199, the same range treated as "cash" by
     * getCashFlowStatement()) — or one specific account when $accountId is given.
     * Unlike getGeneralLedger() (which returns one ledger per account), entries
     * from every resolved account are merged into one running balance, matching
     * the traditional single/double-column cash book format.
     */
    public function getCashBook(?int $accountId = null, mixed $startDate = null, mixed $endDate = null, ?string $sbuCode = null): array
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->when(
                $accountId !== null,
                fn ($q) => $q->whereKey($accountId),
                fn ($q) => $q->whereBetween('code', ['1000', '1199']),
            )
            ->orderBy('code')
            ->get();

        $sbuCode = $this->normalizeSbuCode($sbuCode);

        if ($accounts->isEmpty()) {
            return [
                'period'          => ['start' => $startDate, 'end' => $endDate],
                'accounts'        => [],
                'opening_balance' => 0.0,
                'closing_balance' => 0.0,
                'total_receipts'  => 0.0,
                'total_payments'  => 0.0,
                'entries'         => [],
                'sbu_code'        => $sbuCode,
            ];
        }

        $prefix = config('accounting.table_prefix', 'acct_');
        $accountIds = $accounts->pluck('id')->all();

        $openingBalance = 0.0;

        if ($startDate !== null) {
            $openingQuery = DB::table("{$prefix}journal_entry_lines as l")
                ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
                ->where('je.status', 'posted')
                ->whereIn('l.account_id', $accountIds)
                ->where('je.date', '<', $startDate)
                ->selectRaw(
                    "SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE 0 END) as total_debit,
                    SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as total_credit",
                );

            $openingRow = $this->applySbuFilter($openingQuery, $sbuCode)->first();
            // Cash/bank accounts are always debit-normal (asset) — no isDebitAccount() branch needed.
            $openingBalance = (float) ($openingRow?->total_debit ?? 0) - (float) ($openingRow?->total_credit ?? 0);
        }

        $lineQuery = DB::table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('l.account_id', $accountIds)
            ->when($startDate !== null, fn ($q) => $q->where('je.date', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('je.date', '<', DayRange::endOfDay($endDate)))
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

        $lines = $this->applySbuFilter($lineQuery, $sbuCode)->get();

        $accountLabels = $accounts->keyBy('id')->map(
            fn (Account $a): string => $a->name . ' (' . $a->code . ')',
        );

        $runningBalance = $openingBalance;
        $totalReceipts = 0.0;
        $totalPayments = 0.0;
        $entries = [];

        foreach ($lines as $row) {
            $debit = $row->type === 'debit' ? (float) $row->amount : 0.0;
            $credit = $row->type === 'credit' ? (float) $row->amount : 0.0;

            $totalReceipts += $debit;
            $totalPayments += $credit;
            $runningBalance += $debit - $credit;

            $entries[] = [
                'line_id'          => (int) $row->line_id,
                'journal_entry_id' => (int) $row->journal_entry_id,
                'entry_number'     => $row->entry_number,
                'date'             => $row->date,
                'account_id'       => (int) $row->account_id,
                'account_label'    => $accountLabels->get($row->account_id),
                'reference'        => $row->line_reference ?: $row->journal_reference,
                'journal_type'     => $row->journal_type,
                'description'      => $row->line_description ?: $row->journal_description,
                'sbu_code'         => $row->sbu_code,
                'receipt'          => $debit,
                'payment'          => $credit,
                'running_balance'  => $runningBalance,
            ];
        }

        return [
            'period'   => ['start' => $startDate, 'end' => $endDate],
            'accounts' => $accounts->map(fn (Account $a): array => [
                'id'   => $a->id,
                'code' => $a->code,
                'name' => $a->name,
            ])->all(),
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($runningBalance, 2),
            'total_receipts'  => round($totalReceipts, 2),
            'total_payments'  => round($totalPayments, 2),
            'entries'         => $entries,
            'sbu_code'        => $sbuCode,
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
}
