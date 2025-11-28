<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Models\{JournalEntry};
use Centrex\LaravelAccounting\Services\AccountingService;
use Livewire\{Component};

class AccountingDashboard extends Component
{
    public $dateRange = 'this_month';

    public $startDate;

    public $endDate;

    public function mount(): void
    {
        $this->updateDateRange();
    }

    public function updatedDateRange(): void
    {
        $this->updateDateRange();
    }

    protected function updateDateRange()
    {
        switch ($this->dateRange) {
            case 'today':
                $this->startDate = now()->startOfDay();
                $this->endDate = now()->endOfDay();

                break;
            case 'this_week':
                $this->startDate = now()->startOfWeek();
                $this->endDate = now()->endOfWeek();

                break;
            case 'this_month':
                $this->startDate = now()->startOfMonth();
                $this->endDate = now()->endOfMonth();

                break;
            case 'this_quarter':
                $this->startDate = now()->startOfQuarter();
                $this->endDate = now()->endOfQuarter();

                break;
            case 'this_year':
                $this->startDate = now()->startOfYear();
                $this->endDate = now()->endOfYear();

                break;
        }
    }

    public function render()
    {
        $service = app(AccountingService::class);

        // Get key metrics
        $incomeStatement = $service->getIncomeStatement($this->startDate, $this->endDate);
        $balanceSheet = $service->getBalanceSheet($this->endDate);

        $metrics = [
            'revenue'           => $incomeStatement['revenue']['total'] ?? 0,
            'expenses'          => $incomeStatement['expenses']['total'] ?? 0,
            'net_income'        => $incomeStatement['net_income'] ?? 0,
            'total_assets'      => $balanceSheet['assets']['total'] ?? 0,
            'total_liabilities' => $balanceSheet['liabilities']['total'] ?? 0,
            'total_equity'      => $balanceSheet['equity']['total_with_income'] ?? 0,
        ];

        // Recent journal entries
        $recentEntries = JournalEntry::with(['lines.account'])
            ->latest('date')
            ->limit(10)
            ->get();

        return view('accounting::livewire.accounting-dashboard', [
            'metrics'       => $metrics,
            'recentEntries' => $recentEntries,
        ]);
    }
}
