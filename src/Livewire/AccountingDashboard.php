<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{Account, Bill, Customer, Invoice, JournalEntry, Vendor};
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class AccountingDashboard extends Component
{
    use WithCurrency;

    public string $dateRange = 'this_month';

    public mixed $startDate = null;

    public mixed $endDate = null;

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->updateDateRange();
    }

    public function updatedDateRange(): void
    {
        $this->updateDateRange();
    }

    protected function updateDateRange(): void
    {
        match ($this->dateRange) {
            'today'        => [$this->startDate = now()->startOfDay(),     $this->endDate = now()->endOfDay()],
            'this_week'    => [$this->startDate = now()->startOfWeek(),    $this->endDate = now()->endOfWeek()],
            'this_month'   => [$this->startDate = now()->startOfMonth(),   $this->endDate = now()->endOfMonth()],
            'this_quarter' => [$this->startDate = now()->startOfQuarter(), $this->endDate = now()->endOfQuarter()],
            'this_year'    => [$this->startDate = now()->startOfYear(),    $this->endDate = now()->endOfYear()],
            default        => null,
        };
    }

    private function monthlyRevenueExpenses(): array
    {
        $prefix = config('accounting.table_prefix') ?: 'acct_';
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $year = now()->year;

        $revenueIds = Account::where('type', 'revenue')->where('is_active', true)->pluck('id');
        $expenseIds = Account::where('type', 'expense')->where('is_active', true)->pluck('id');

        if ($revenueIds->isEmpty() && $expenseIds->isEmpty()) {
            return ['series' => [], 'categories' => []];
        }

        $allIds = $revenueIds->merge($expenseIds)->unique()->values();

        $rows = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')->whereNull('je.deleted_at')->whereYear('je.date', $year)
            ->whereIn('l.account_id', $allIds)
            ->selectRaw('MONTH(je.date) as month,
                SUM(CASE WHEN l.account_id IN (' . $revenueIds->implode(',') . ") AND l.type = 'credit' THEN l.amount
                         WHEN l.account_id IN (" . $revenueIds->implode(',') . ") AND l.type = 'debit'  THEN -l.amount ELSE 0 END) as revenue,
                SUM(CASE WHEN l.account_id IN (" . $expenseIds->implode(',') . ") AND l.type = 'debit'  THEN l.amount
                         WHEN l.account_id IN (" . $expenseIds->implode(',') . ") AND l.type = 'credit' THEN -l.amount ELSE 0 END) as expenses")
            ->groupByRaw('MONTH(je.date)')->orderByRaw('MONTH(je.date)')
            ->get()->keyBy('month');

        $categories = [];
        $revenue = [];
        $expenses = [];

        for ($m = 1; $m <= now()->month; $m++) {
            $categories[] = now()->startOfYear()->addMonths($m - 1)->format('M');
            $revenue[] = round((float) ($rows->get($m)?->revenue ?? 0), 2);
            $expenses[] = round((float) ($rows->get($m)?->expenses ?? 0), 2);
        }

        return [
            'series'     => [
                ['name' => 'Revenue', 'data' => $revenue],
                ['name' => 'Expenses', 'data' => $expenses],
            ],
            'categories' => $categories,
        ];
    }

    private function monthlyCashFlow(): array
    {
        $prefix = config('accounting.table_prefix') ?: 'acct_';
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $year = now()->year;

        $cashAccount = Account::where('code', '1000')->where('is_active', true)->first();

        if (!$cashAccount) {
            return ['series' => [], 'categories' => []];
        }

        $rows = DB::connection($connection)
            ->table("{$prefix}journal_entry_lines as l")
            ->join("{$prefix}journal_entries as je", 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', 'posted')->whereNull('je.deleted_at')->whereYear('je.date', $year)
            ->where('l.account_id', $cashAccount->id)
            ->selectRaw("MONTH(je.date) as month,
                SUM(CASE WHEN l.type = 'debit'  THEN l.amount ELSE 0 END) as inflow,
                SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END) as outflow")
            ->groupByRaw('MONTH(je.date)')->orderByRaw('MONTH(je.date)')
            ->get()->keyBy('month');

        $categories = [];
        $inflow = [];
        $outflow = [];
        $net = [];

        for ($m = 1; $m <= now()->month; $m++) {
            $categories[] = now()->startOfYear()->addMonths($m - 1)->format('M');
            $in = round((float) ($rows->get($m)?->inflow ?? 0), 2);
            $out = round((float) ($rows->get($m)?->outflow ?? 0), 2);
            $inflow[] = $in;
            $outflow[] = $out;
            $net[] = round($in - $out, 2);
        }

        return [
            'series'     => [
                ['name' => 'Inflow', 'data' => $inflow],
                ['name' => 'Outflow', 'data' => $outflow],
                ['name' => 'Net', 'data' => $net],
            ],
            'categories' => $categories,
        ];
    }

    private function cashFlowForecast(array $cashFlowData): array
    {
        $netSeries = collect($cashFlowData['series'])->firstWhere('name', 'Net');
        $actual = $netSeries['data'] ?? [];
        $n = count($actual);
        $allMonths = 12;

        $allCategories = [];

        for ($m = 1; $m <= $allMonths; $m++) {
            $allCategories[] = now()->startOfYear()->addMonths($m - 1)->format('M');
        }

        if ($n < 2) {
            return [
                'series'     => [
                    ['name' => 'Actual Net', 'type' => 'area', 'data' => array_pad($actual, $allMonths, null)],
                    ['name' => 'Forecast',   'type' => 'line', 'data' => array_fill(0, $allMonths, null)],
                ],
                'categories' => $allCategories,
            ];
        }

        $xSum = $ySum = $xySum = $xxSum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $xSum += $x;
            $ySum += $actual[$i];
            $xySum += $x * $actual[$i];
            $xxSum += $x * $x;
        }

        $slope = ($n * $xySum - $xSum * $ySum) / ($n * $xxSum - $xSum * $xSum);
        $intercept = ($ySum - $slope * $xSum) / $n;

        $actualSeries = [];
        $forecastSeries = [];

        for ($m = 1; $m <= $allMonths; $m++) {
            if ($m <= $n) {
                $actualSeries[] = round((float) $actual[$m - 1], 2);
                $forecastSeries[] = ($m === $n) ? round((float) $actual[$m - 1], 2) : null;
            } else {
                $actualSeries[] = null;
                $forecastSeries[] = round($slope * $m + $intercept, 2);
            }
        }

        return [
            'series'     => [
                ['name' => 'Actual Net', 'type' => 'area', 'data' => $actualSeries],
                ['name' => 'Forecast',   'type' => 'line', 'data' => $forecastSeries],
            ],
            'categories' => $allCategories,
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $service = app(Accounting::class);

        // Period P&L + balance sheet
        $incomeStatement = $service->getIncomeStatement($this->startDate, $this->endDate);
        $balanceSheet = $service->getBalanceSheet($this->endDate);
        $cashFlow = $service->getCashFlowStatement($this->startDate, $this->endDate);

        $metrics = [
            'revenue'           => $incomeStatement['revenue']['total'] ?? 0,
            'expenses'          => $incomeStatement['expenses']['total'] ?? 0,
            'net_income'        => $incomeStatement['net_income'] ?? 0,
            'total_assets'      => $balanceSheet['assets']['total'] ?? 0,
            'total_liabilities' => $balanceSheet['liabilities']['total'] ?? 0,
            'total_equity'      => $balanceSheet['equity']['total_with_income'] ?? 0,
            'operating_cf'      => $cashFlow['operating_activities'] ?? 0,
        ];

        // Invoice stats
        $invoiceStats = [
            'draft_count'   => Invoice::where('status', 'draft')->count(),
            'sent_count'    => Invoice::whereIn('status', ['sent', 'issued'])->count(),
            'partial_count' => Invoice::where('status', 'partially_settled')->count(),
            'overdue_count' => Invoice::where('status', 'overdue')->count(),
            'overdue_total' => Invoice::where('status', 'overdue')
                ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as val')->value('val') ?? 0,
            'outstanding_ar' => Invoice::whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])
                ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as val')->value('val') ?? 0,
        ];

        // Bill stats
        $billStats = [
            'draft_count'   => Bill::where('status', 'draft')->count(),
            'sent_count'    => Bill::whereIn('status', ['sent', 'issued'])->count(),
            'partial_count' => Bill::where('status', 'partially_settled')->count(),
            'overdue_count' => Bill::where('status', 'overdue')->count(),
            'overdue_total' => Bill::where('status', 'overdue')
                ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as val')->value('val') ?? 0,
            'outstanding_ap' => Bill::whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])
                ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as val')->value('val') ?? 0,
        ];

        $ledgerStats = [
            'posted_count' => JournalEntry::where('status', 'posted')
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->count(),
            'draft_count' => JournalEntry::where('status', 'draft')->count(),
            'void_count' => JournalEntry::where('status', 'void')
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->count(),
        ];

        // Counts
        $customerCount = Customer::where('is_active', true)->count();
        $vendorCount = Vendor::where('is_active', true)->count();

        // Recent entries
        $recentInvoices = Invoice::with('customer')
            ->latest('invoice_date')->limit(5)->get();

        $recentBills = Bill::with('vendor')
            ->latest('bill_date')->limit(5)->get();

        $recentEntries = JournalEntry::with(['lines.account'])
            ->latest('date')->limit(8)->get();

        $revenueExpensesChart = $this->monthlyRevenueExpenses();
        $cashFlowChart = $this->monthlyCashFlow();
        $forecastChart = $this->cashFlowForecast($cashFlowChart);
        $balanceChart = [
            'series'     => [
                max(0, (float) $metrics['total_assets']),
                max(0, (float) $metrics['total_liabilities']),
                max(0, (float) $metrics['total_equity']),
            ],
            'categories' => ['Assets', 'Liabilities', 'Equity'],
        ];

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.accounting-dashboard', compact(
            'metrics',
            'cashFlow',
            'invoiceStats',
            'billStats',
            'ledgerStats',
            'customerCount',
            'vendorCount',
            'recentInvoices',
            'recentBills',
            'recentEntries',
            'revenueExpensesChart',
            'cashFlowChart',
            'forecastChart',
            'balanceChart',
        ))->layout($layout, ['title' => __('Accounting Dashboard')]);
    }
}
