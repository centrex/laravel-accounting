<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{Account, Bill, Customer, FiscalPeriod, Invoice, JournalEntry, Vendor};
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
            'last_month'   => [$this->startDate = now()->subMonthNoOverflow()->startOfMonth(), $this->endDate = now()->subMonthNoOverflow()->endOfMonth()],
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
            'series' => [
                ['name' => 'Revenue', 'data' => $revenue],
                ['name' => 'Expenses', 'data' => $expenses],
            ],
            'categories' => $categories,
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        // Revenue/expenses/net-income/assets/liabilities/equity KPIs, the full AR/AP
        // outstanding totals, Balance Snapshot, and Current Assets all moved to their own
        // lazy-loaded Livewire components (AccountingKpiCard, AccountingReceivablesCard,
        // AccountingPayablesCard, AccountingBalanceSnapshotCard, AccountingCurrentAssetsCard
        // — see accounting-dashboard.blade.php) since together they ran ~16+ queries,
        // several scanning the full posted journal-entry history or N+1'ing per open
        // invoice/bill.
        //
        // The overdue-only figures below stay here (not lazy) because the "N invoices
        // overdue" banner at the top of the page is meant to be visible immediately, not
        // hidden behind a loading placeholder — and "overdue" is normally a much smaller
        // subset of invoices/bills than "all outstanding", so the N+1 on base_balance is
        // bounded.
        $invoiceOverdue = [
            'count' => Invoice::where('status', 'overdue')->count(),
            'total' => Invoice::where('status', 'overdue')->get()->sum('base_balance'),
        ];

        $billOverdue = [
            'count' => Bill::where('status', 'overdue')->count(),
            'total' => Bill::where('status', 'overdue')->get()->sum('base_balance'),
        ];

        $ledgerStats = [
            'posted_count' => JournalEntry::where('status', 'posted')
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->count(),
            'draft_count'     => JournalEntry::where('status', 'draft')->count(),
            'submitted_count' => JournalEntry::where('status', 'submitted')->count(),
            'void_count'      => JournalEntry::where('status', 'void')
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->count(),
        ];

        $pendingJournals = JournalEntry::with(['submitter', 'lines'])
            ->where('status', 'submitted')
            ->latest('submitted_at')
            ->limit(5)
            ->get();

        $openPeriod = FiscalPeriod::query()
            ->where('is_closed', false)
            ->orderBy('end_date')
            ->first();

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

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.accounting-dashboard', compact(
            'invoiceOverdue',
            'billOverdue',
            'ledgerStats',
            'customerCount',
            'vendorCount',
            'recentInvoices',
            'recentBills',
            'recentEntries',
            'pendingJournals',
            'openPeriod',
            'revenueExpensesChart',
        ))->layout($layout, ['title' => __('Accounting Dashboard')]);
    }
}
