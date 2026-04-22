<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\Account;
use Livewire\Component;

class GeneralLedger extends Component
{
    use WithCurrency;

    public string $startDate = '';

    public string $endDate = '';

    public string $accountId = '';

    public string $sbuCode = '';

    public ?array $ledgerData = null;

    public string $currency;

    protected $queryString = ['accountId', 'startDate', 'endDate', 'sbuCode'];

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->startDate = $this->startDate !== '' ? $this->startDate : now()->startOfMonth()->format('Y-m-d');
        $this->endDate = $this->endDate !== '' ? $this->endDate : now()->format('Y-m-d');
    }

    public function generateLedger(): void
    {
        $this->validate([
            'accountId' => ['nullable', 'integer'],
            'sbuCode' => ['nullable', 'string', 'max:50'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
        ]);

        try {
            $this->ledgerData = app(Accounting::class)->getGeneralLedger(
                $this->accountId !== '' ? (int) $this->accountId : null,
                $this->startDate ?: null,
                $this->endDate ?: null,
                $this->sbuCode ?: null,
            );
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function exportPdf()
    {
        if ($this->ledgerData === null) {
            session()->flash('error', 'Generate the ledger before exporting it.');

            return null;
        }

        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            session()->flash('error', 'PDF export is not available in this environment.');

            return null;
        }

        $ledgerData = app(Accounting::class)->getGeneralLedger(
            $this->accountId !== '' ? (int) $this->accountId : null,
            $this->startDate ?: null,
            $this->endDate ?: null,
            $this->sbuCode ?: null,
        );

        $selectedAccount = $this->accountId !== ''
            ? Account::query()->find((int) $this->accountId)
            : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('accounting::pdf.general-ledger', [
            'ledgerData' => $ledgerData,
            'selectedAccount' => $selectedAccount,
            'selectedSbuCode' => $this->sbuCode !== '' ? strtoupper($this->sbuCode) : null,
            'currency' => $this->currency,
            'generatedAt' => now(),
        ]);

        return response()->streamDownload(
            static fn () => print($pdf->output()),
            'general-ledger-' . now()->format('Ymd_His') . '.pdf',
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.general-ledger', [
            'accounts' => $accounts,
        ])->layout($layout, ['title' => __('General Ledger')]);
    }
}
