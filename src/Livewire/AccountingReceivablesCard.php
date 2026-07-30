<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\Invoice;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

/**
 * Split out of AccountingDashboard: outstanding_ar/overdue_total sum Invoice::$base_balance
 * across every open invoice, which runs 2 extra queries per invoice (discounts + credit
 * memos — see Invoice::getBalanceAttribute()). Lazy-loaded and cached briefly so that N+1
 * doesn't sit on the dashboard's critical render path.
 */
class AccountingReceivablesCard extends Component
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
    public function invoiceStats(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'receivables-card'),
            fn (): array => [
                'draft_count'    => Invoice::where('status', 'draft')->count(),
                'sent_count'     => Invoice::whereIn('status', ['sent', 'issued'])->count(),
                'partial_count'  => Invoice::where('status', 'partially_settled')->count(),
                'overdue_count'  => Invoice::where('status', 'overdue')->count(),
                'overdue_total'  => Invoice::where('status', 'overdue')->get()->sum('base_balance'),
                'outstanding_ar' => Invoice::whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])->get()->sum('base_balance'),
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
        return view('accounting::livewire.accounting-receivables-card', [
            'invoiceStats' => $this->invoiceStats(),
        ]);
    }
}
