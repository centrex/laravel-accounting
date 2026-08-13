<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Livewire\Component;

class ApAgingReport extends Component
{
    use WithCurrency;

    public string $asOfDate = '';

    public string $currency;

    public function mount(): void
    {
        $this->asOfDate = now()->toDateString();
        $this->currency = self::getCurrency();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $report = app(Accounting::class)->getApAging($this->asOfDate ?: null);

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.ap-aging-report', compact('report'))
            ->layout($layout, ['title' => __('AP Aging Report')]);
    }
}
