<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Livewire\Component;

class FinancialReports extends Component
{
    use WithCurrency;

    public string $reportType = 'trial_balance';

    public string $startDate;

    public string $endDate;

    public ?array $reportData = null;

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function updatedReportType(): void
    {
        $this->reportData = null;
    }

    public function generateReport(): void
    {
        $service = app(Accounting::class);

        try {
            $this->reportData = match ($this->reportType) {
                'trial_balance'    => $service->getTrialBalance($this->startDate, $this->endDate),
                'balance_sheet'    => $service->getBalanceSheet($this->endDate),
                'income_statement' => $service->getIncomeStatement($this->startDate, $this->endDate),
                'cash_flow'        => $service->getCashFlowStatement($this->startDate, $this->endDate),
                default            => null,
            };
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function exportPdf(): void
    {
        session()->flash('message', 'Export feature coming soon!');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.financial-reports')->layout($layout, ['title' => __('Financial Reports')]);
    }
}
