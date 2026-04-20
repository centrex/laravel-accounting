<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\Bill;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class BillDetails extends Component
{
    public Bill $bill;

    public function mount(Bill $bill): void
    {
        $this->bill = $bill->load([
            'vendor',
            'items',
            'payments.journalEntry',
            'journalEntry.lines.account',
        ]);
    }

    public function render(): View
    {
        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.bill-details')
            ->layout($layout, ['title' => __('Bill Details')]);
    }
}
