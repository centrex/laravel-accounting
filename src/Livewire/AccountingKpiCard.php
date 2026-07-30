<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Revenue/Expenses/Net Income/Assets/Liabilities/Equity KPI row — split out of
 * AccountingDashboard because getIncomeStatement()+getBalanceSheet() together run ~16
 * queries, several of them scanning the full posted journal-entry-lines history. Loaded
 * lazily so it doesn't block the rest of the dashboard, and cached briefly since the
 * underlying figures don't change second-to-second.
 */
class AccountingKpiCard extends Component
{
    use CachesData;
    use WithCurrency;

    #[Reactive]
    public mixed $startDate = null;

    #[Reactive]
    public mixed $endDate = null;

    public string $currency;

    public function mount(): void
    {
        $this->currency = self::getCurrency();
        $this->cacheTtl = 300;
    }

    /** @return array<string, float> */
    public function metrics(): array
    {
        return $this->rememberCache(
            $this->cacheKey('accounting', 'kpi-card', (string) $this->startDate, (string) $this->endDate),
            function (): array {
                $service = app(Accounting::class);
                $incomeStatement = $service->getIncomeStatement($this->startDate, $this->endDate);
                $balanceSheet = $service->getBalanceSheet($this->endDate);

                return [
                    'revenue'           => (float) ($incomeStatement['revenue']['total'] ?? 0),
                    'expenses'          => (float) ($incomeStatement['expenses']['total'] ?? 0),
                    'net_income'        => (float) ($incomeStatement['net_income'] ?? 0),
                    'total_assets'      => (float) ($balanceSheet['assets']['total'] ?? 0),
                    'total_liabilities' => (float) ($balanceSheet['liabilities']['total'] ?? 0),
                    'total_equity'      => (float) ($balanceSheet['equity']['total_with_income'] ?? 0),
                ];
            },
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading" class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
                @for ($i = 0; $i < 6; $i++)
                    <div class="rounded-2xl border border-base-300 bg-base-100 p-4 shadow-theme-xs animate-pulse space-y-2">
                        <div class="h-3 w-16 rounded bg-base-300"></div>
                        <div class="h-5 w-24 rounded bg-base-300"></div>
                        <div class="h-2 w-20 rounded bg-base-300"></div>
                    </div>
                @endfor
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('accounting::livewire.accounting-kpi-card', [
            'metrics' => $this->metrics(),
        ]);
    }
}
