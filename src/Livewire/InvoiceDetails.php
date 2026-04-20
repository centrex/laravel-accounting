<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class InvoiceDetails extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice->load([
            'customer',
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

        return view('accounting::livewire.invoice-details')
            ->layout($layout, ['title' => __('Invoice Details')]);
    }
}
