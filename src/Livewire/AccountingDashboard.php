<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Concerns\WithCurrency;
use Centrex\LaravelAccounting\Models\JournalEntry;
use Livewire\Component;

class AccountingDashboard extends Component
{
    use WithCurrency;

    public $dateRange = 'this_month';

    public $startDate;

    public $endDate;

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
            'today' => [
                $this->startDate = now()->startOfDay(),
                $this->endDate = now()->endOfDay(),
            ],
            'this_week' => [
                $this->startDate = now()->startOfWeek(),
                $this->endDate = now()->endOfWeek(),
            ],
            'this_month' => [
                $this->startDate = now()->startOfMonth(),
                $this->endDate = now()->endOfMonth(),
            ],
            'this_quarter' => [
                $this->startDate = now()->startOfQuarter(),
                $this->endDate = now()->endOfQuarter(),
            ],
            'this_year' => [
                $this->startDate = now()->startOfYear(),
                $this->endDate = now()->endOfYear(),
            ],
            default => null,
        };
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $service = app(Accounting::class);

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

        $recentEntries = JournalEntry::with(['lines.account'])
            ->latest('date')
            ->limit(10)
            ->get();

        $layout = view()->exists('layouts.app')
                ? 'layouts.app'
                : 'components.layouts.app';

        return view('accounting::livewire.accounting-dashboard', [
            'metrics'       => $metrics,
            'recentEntries' => $recentEntries,
        ])->layout($layout, ['title' => __('Accounting Dashboard')]);
    }
}
