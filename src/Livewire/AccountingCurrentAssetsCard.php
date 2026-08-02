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
 * Liquid-assets list (account codes < 1500), split out of AccountingDashboard alongside
 * AccountingBalanceSnapshotCard. See that class's docblock — both share one cache key for
 * getBalanceSheet($endDate) so it's only computed once, whichever card's lazy request wins.
 */
class AccountingCurrentAssetsCard extends Component
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

    public function balanceSheet(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'balance-sheet', (string) $this->endDate),
            fn (): array => $this->serializeBalanceSheetAccounts(app(Accounting::class)->getBalanceSheet($this->endDate)),
        );
    }

    /**
     * Clears the cached balance sheet so the next render recomputes it — forgets the same
     * key AccountingBalanceSnapshotCard shares, so that card picks up fresh data too on its
     * next render rather than continuing to serve the now-stale cached entry.
     */
    public function refresh(): void
    {
        $this->forgetCache($this->cacheKey('accounting', 'balance-sheet', (string) $this->endDate));
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <x-tallui-card title="Current Assets" icon="o-banknotes" subtitle="Liquid assets available">
                <div role="status" aria-label="Loading" class="animate-pulse space-y-3">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="flex items-center justify-between gap-3">
                            <div class="h-3 w-32 rounded bg-base-300"></div>
                            <div class="h-3 w-16 rounded bg-base-300"></div>
                        </div>
                    @endfor
                </div>
            </x-tallui-card>
            BLADE);
    }

    public function render(): View
    {
        $balanceSheet = $this->balanceSheet();

        $currentAssets = collect($balanceSheet['assets']['accounts'] ?? [])
            ->filter(fn (array $item) => (int) ($item['account']['code'] ?? 9999) < 1500)
            ->values();

        return view('accounting::livewire.accounting-current-assets-card', [
            'currentAssets'     => $currentAssets,
            'currentAssetTotal' => $currentAssets->sum(fn (array $item) => (float) ($item['balance'] ?? 0)),
        ]);
    }
}
