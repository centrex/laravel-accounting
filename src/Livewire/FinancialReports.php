<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Services\AccountingService;
use Livewire\Component;

class FinancialReports extends Component
{
    public $reportType = 'trial_balance';

    public $startDate;

    public $endDate;

    public $reportData;

    public function mount(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    public function generateReport(): void
    {
        $service = app(AccountingService::class);

        try {
            switch ($this->reportType) {
                case 'trial_balance':
                    $this->reportData = $service->getTrialBalance($this->startDate, $this->endDate);

                    break;

                case 'balance_sheet':
                    $this->reportData = $service->getBalanceSheet($this->endDate);

                    break;

                case 'income_statement':
                    $this->reportData = $service->getIncomeStatement($this->startDate, $this->endDate);

                    break;

                case 'cash_flow':
                    $this->reportData = $service->getCashFlowStatement($this->startDate, $this->endDate);

                    break;
            }
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function exportPdf(): void
    {
        // Implement PDF export logic
        session()->flash('message', 'Export feature coming soon!');
    }

    public function render()
    {
        return view('accounting::livewire.financial-reports');
    }
}
