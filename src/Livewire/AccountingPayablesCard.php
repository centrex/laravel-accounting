<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\Bill;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

/**
 * Mirrors AccountingReceivablesCard for Accounts Payable — Bill::$base_balance runs 1 extra
 * query per bill (see Bill::getBalanceAttribute()).
 */
class AccountingPayablesCard extends Component
{
    use CachesData;
    use WithCurrency;

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->cacheTtl = 300;
    }

    /** @return array<string, int|float> */
    public function billStats(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'payables-card'),
            fn (): array => [
                'draft_count'    => Bill::where('status', 'draft')->count(),
                'sent_count'     => Bill::whereIn('status', ['sent', 'issued'])->count(),
                'partial_count'  => Bill::where('status', 'partially_settled')->count(),
                'overdue_count'  => Bill::where('status', 'overdue')->count(),
                'overdue_total'  => Bill::where('status', 'overdue')->get()->sum('base_balance'),
                'outstanding_ap' => Bill::whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])->get()->sum('base_balance'),
            ],
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading" class="rounded-2xl border border-base-200 bg-base-100 h-full p-4 animate-pulse space-y-3">
                <div class="h-3 w-20 rounded bg-base-300"></div>
                <div class="h-6 w-28 rounded bg-base-300"></div>
                <div class="h-4 w-32 rounded bg-base-300"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('accounting::livewire.accounting-payables-card', [
            'billStats' => $this->billStats(),
        ]);
    }
}
