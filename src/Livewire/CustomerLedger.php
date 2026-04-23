<?php

declare(strict_types=1);

namespace Centrex\Accounting\Livewire;

use Carbon\Carbon;
use Centrex\Accounting\Models\{Customer, Invoice, Payment};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CustomerLedger extends Component
{
    public Customer $customer;

    public string $startDate = '';

    public string $endDate = '';

    protected array $queryString = ['startDate', 'endDate'];

    public function mount(Customer $customer): void
    {
        $this->customer  = $customer;
        $this->startDate = $this->startDate ?: now()->startOfYear()->format('Y-m-d');
        $this->endDate   = $this->endDate   ?: now()->format('Y-m-d');
    }

    private function invoiceIds(): \Illuminate\Support\Collection
    {
        return Invoice::where('customer_id', $this->customer->id)->pluck('id');
    }

    private function openingBalance(\Illuminate\Support\Collection $ids): float
    {
        if ($this->startDate === '') {
            return 0.0;
        }

        $invoiced = Invoice::where('customer_id', $this->customer->id)
            ->whereNotNull('journal_entry_id')
            ->where('invoice_date', '<', $this->startDate)
            ->sum('total');

        $paid = Payment::where('payable_type', Invoice::class)
            ->whereIn('payable_id', $ids)
            ->where('payment_date', '<', $this->startDate)
            ->sum('amount');

        return round((float) $invoiced - (float) $paid, 2);
    }

    public function getLedgerData(): array
    {
        $ids = $this->invoiceIds();

        $invoices = Invoice::where('customer_id', $this->customer->id)
            ->whereNotNull('journal_entry_id')
            ->when($this->startDate, fn ($q) => $q->where('invoice_date', '>=', $this->startDate))
            ->when($this->endDate,   fn ($q) => $q->where('invoice_date', '<=', $this->endDate))
            ->orderBy('invoice_date')->orderBy('id')
            ->get()
            ->map(fn ($inv) => [
                'date'        => Carbon::parse($inv->invoice_date),
                'sort_key'    => Carbon::parse($inv->invoice_date)->format('Y-m-d') . '_1_' . str_pad((string) $inv->id, 10, '0', STR_PAD_LEFT),
                'type'        => 'invoice',
                'reference'   => $inv->invoice_number,
                'description' => 'Invoice',
                'status'      => $inv->status->value ?? (string) $inv->status,
                'debit'       => (float) $inv->total,
                'credit'      => 0.0,
                'link'        => route('accounting.invoices.show', $inv->id),
            ]);

        $payments = Payment::where('payable_type', Invoice::class)
            ->whereIn('payable_id', $ids)
            ->when($this->startDate, fn ($q) => $q->where('payment_date', '>=', $this->startDate))
            ->when($this->endDate,   fn ($q) => $q->where('payment_date', '<=', $this->endDate))
            ->orderBy('payment_date')->orderBy('id')
            ->get()
            ->map(fn ($pmt) => [
                'date'        => Carbon::parse($pmt->payment_date),
                'sort_key'    => Carbon::parse($pmt->payment_date)->format('Y-m-d') . '_2_' . str_pad((string) $pmt->id, 10, '0', STR_PAD_LEFT),
                'type'        => 'payment',
                'reference'   => $pmt->payment_number,
                'description' => 'Payment — ' . ucwords(str_replace('_', ' ', (string) ($pmt->payment_method ?? ''))),
                'status'      => null,
                'debit'       => 0.0,
                'credit'      => (float) $pmt->amount,
                'link'        => null,
            ]);

        $entries     = $invoices->merge($payments)->sortBy('sort_key')->values();
        $opening     = $this->openingBalance($ids);
        $balance     = $opening;
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        $rows        = [];

        foreach ($entries as $entry) {
            $balance     += $entry['debit'] - $entry['credit'];
            $totalDebit  += $entry['debit'];
            $totalCredit += $entry['credit'];
            $rows[]       = array_merge($entry, ['balance' => $balance]);
        }

        return [
            'opening'      => $opening,
            'entries'      => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'closing'      => $balance,
        ];
    }

    public function render(): View
    {
        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.customer-ledger', [
            'ledger' => $this->getLedgerData(),
        ])->layout($layout, ['title' => 'Customer Ledger — ' . $this->customer->name]);
    }
}
