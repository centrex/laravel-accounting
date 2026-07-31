<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\{SerializesBalanceSheetAccounts, WithCurrency};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Assets/Liabilities/Equity pie chart, split out of AccountingDashboard alongside
 * AccountingCurrentAssetsCard. Both independently call getBalanceSheet($endDate) — rather
 * than coupling the two into one component (Livewire requires a single root element, which
 * would fight the dashboard's CSS grid layout), they share the exact same cache key so
 * whichever mounts first pays the query cost and the other reads it straight from cache.
 *
 * Applies SerializesBalanceSheetAccounts even though this card never reads the embedded
 * account rows itself — see that trait's docblock. Whichever of these two cards' lazy
 * request lands first is the one that actually populates the shared cache entry, so both
 * must sanitize identically or the entry's shape depends on a race.
 */
class AccountingBalanceSnapshotCard extends Component
{
    use CachesData;
    use SerializesBalanceSheetAccounts;
    use WithCurrency;

    #[Reactive]
    public mixed $endDate = null;

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->cacheTtl = 300;
    }

    /**
     * Cached under the same key AccountingCurrentAssetsCard uses, so whichever card's lazy
     * request lands first pays for getBalanceSheet() and the other reads the cached array.
     */
    public function balanceSheet(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'balance-sheet', (string) $this->endDate),
            fn (): array => $this->serializeBalanceSheetAccounts(app(Accounting::class)->getBalanceSheet($this->endDate)),
        );
    }

    /** @return array{total_assets: float, total_liabilities: float, total_equity: float} */
    public function balances(): array
    {
        $balanceSheet = $this->balanceSheet();

        return [
            'total_assets'      => (float) ($balanceSheet['assets']['total'] ?? 0),
            'total_liabilities' => (float) ($balanceSheet['liabilities']['total'] ?? 0),
            'total_equity'      => (float) ($balanceSheet['equity']['total_with_income'] ?? 0),
        ];
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <x-tallui-card title="Balance Snapshot" icon="o-chart-pie">
                <div role="status" aria-label="Loading" class="animate-pulse space-y-3">
                    <div class="h-48 w-full rounded bg-base-300"></div>
                    <div class="h-4 w-full rounded bg-base-300"></div>
                    <div class="h-4 w-full rounded bg-base-300"></div>
                    <div class="h-4 w-full rounded bg-base-300"></div>
                </div>
            </x-tallui-card>
            BLADE);
    }

    public function render(): View
    {
        $metrics = $this->balances();

        return view('accounting::livewire.accounting-balance-snapshot-card', [
            'metrics'      => $metrics,
            'balanceChart' => [
                'series' => [
                    max(0, $metrics['total_assets']),
                    max(0, $metrics['total_liabilities']),
                    max(0, $metrics['total_equity']),
                ],
                'categories' => ['Assets', 'Liabilities', 'Equity'],
            ],
        ]);
    }
}
