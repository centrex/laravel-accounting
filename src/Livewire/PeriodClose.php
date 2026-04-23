<?php

declare(strict_types=1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Exceptions\AccountingException;
use Centrex\Accounting\Models\{FiscalPeriod, FiscalYear, PeriodInventorySnapshot};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PeriodClose extends Component
{
    use WithCurrency;

    public ?int $selectedPeriodId = null;

    public bool $snapshotInventory = true;

    public bool $confirmed = false;

    public ?array $checks = null;

    public ?array $result = null;

    public string $errorMessage = '';

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
    }

    public function updatedSelectedPeriodId(): void
    {
        $this->checks = null;
        $this->result = null;
        $this->confirmed = false;
        $this->errorMessage = '';
    }

    public function runChecks(): void
    {
        $this->result = null;
        $this->errorMessage = '';

        if (!$this->selectedPeriodId) {
            $this->errorMessage = 'Please select a period first.';

            return;
        }

        $period = FiscalPeriod::find($this->selectedPeriodId);

        if (!$period) {
            $this->errorMessage = 'Period not found.';

            return;
        }

        $this->checks = app(Accounting::class)->getPeriodCloseChecks($period);
    }

    public function closePeriod(): void
    {
        $this->errorMessage = '';

        if (!$this->selectedPeriodId) {
            $this->errorMessage = 'Please select a period first.';

            return;
        }

        $period = FiscalPeriod::find($this->selectedPeriodId);

        if (!$period) {
            $this->errorMessage = 'Period not found.';

            return;
        }

        if ($this->checks && $this->checks['has_blockers']) {
            $this->errorMessage = 'There are unposted journal entries in this period. Post or delete them before closing.';

            return;
        }

        try {
            $this->result = app(Accounting::class)->closeFiscalPeriod($period, $this->snapshotInventory);
            $this->checks = null;
            $this->confirmed = false;
            $this->selectedPeriodId = null;
            session()->flash('success', "Period '{$this->result['period']->name}' closed successfully.");
        } catch (AccountingException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMessage = 'An unexpected error occurred: ' . $e->getMessage();
        }
    }

    public function render(): View
    {
        $fiscalYears = FiscalYear::query()
            ->with(['periods' => fn ($q) => $q->orderBy('start_date')])
            ->where('is_closed', false)
            ->orderByDesc('start_date')
            ->get();

        $inventoryEnabled = config('inventory.erp.accounting.enabled', false)
            && class_exists(\Centrex\Inventory\Models\WarehouseProduct::class);

        $recentSnapshots = PeriodInventorySnapshot::query()
            ->with('fiscalPeriod')
            ->orderByDesc('snapshot_date')
            ->take(5)
            ->get()
            ->unique('fiscal_period_id');

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.period-close', [
            'fiscalYears'      => $fiscalYears,
            'inventoryEnabled' => $inventoryEnabled,
            'recentSnapshots'  => $recentSnapshots,
        ])->layout($layout, ['title' => __('Period Close')]);
    }
}
