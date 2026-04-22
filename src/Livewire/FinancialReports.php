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

    public string $sbuCode = '';

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
        try {
            $this->reportData = $this->resolveReportData();
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function exportPdf()
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            session()->flash('error', 'PDF export is not available in this environment.');

            return null;
        }

        try {
            $reportData = $this->resolveReportData();

            if ($reportData === null) {
                session()->flash('error', 'Generate a report before exporting it.');

                return null;
            }

            $this->reportData = $reportData;
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());

            return null;
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('accounting::pdf.financial-report', [
            'reportType' => $this->reportType,
            'reportTitle' => $this->reportTitle(),
            'reportData' => $this->reportData,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'sbuCode' => $this->normalizedSbuCode(),
            'currency' => $this->currency,
            'generatedAt' => now(),
        ]);

        return response()->streamDownload(
            static fn () => print($pdf->output()),
            $this->pdfFilename(),
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.financial-reports')->layout($layout, ['title' => __('Financial Reports')]);
    }

    private function resolveReportData(): ?array
    {
        $service = app(Accounting::class);

        return match ($this->reportType) {
            'trial_balance'    => $service->getTrialBalance($this->startDate, $this->endDate, $this->normalizedSbuCode()),
            'balance_sheet'    => $service->getBalanceSheet($this->endDate, $this->normalizedSbuCode()),
            'income_statement' => $service->getIncomeStatement($this->startDate, $this->endDate, $this->normalizedSbuCode()),
            'cash_flow'        => $service->getCashFlowStatement($this->startDate, $this->endDate, $this->normalizedSbuCode()),
            default            => null,
        };
    }

    private function normalizedSbuCode(): ?string
    {
        $value = strtoupper(trim($this->sbuCode));

        return $value !== '' ? $value : null;
    }

    private function reportTitle(): string
    {
        return match ($this->reportType) {
            'trial_balance' => 'Trial Balance',
            'balance_sheet' => 'Balance Sheet',
            'income_statement' => 'Income Statement',
            'cash_flow' => 'Cash Flow Statement',
            default => 'Financial Report',
        };
    }

    private function pdfFilename(): string
    {
        return str($this->reportType)
            ->replace('_', '-')
            ->append('-' . now()->format('Ymd_His') . '.pdf')
            ->toString();
    }
}
