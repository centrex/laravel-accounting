<?php

declare(strict_types=1);

namespace Centrex\Accounting\Livewire;

use Carbon\Carbon;
use Centrex\Accounting\Models\{Bill, Payment, Vendor};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class VendorLedger extends Component
{
    public Vendor $vendor;

    public string $startDate = '';

    public string $endDate = '';

    protected array $queryString = ['startDate', 'endDate'];

    public function mount(Vendor $vendor): void
    {
        $this->vendor    = $vendor;
        $this->startDate = $this->startDate ?: now()->startOfYear()->format('Y-m-d');
        $this->endDate   = $this->endDate   ?: now()->format('Y-m-d');
    }

    private function billIds(): \Illuminate\Support\Collection
    {
        return Bill::where('vendor_id', $this->vendor->id)->pluck('id');
    }

    private function openingBalance(\Illuminate\Support\Collection $ids): float
    {
        if ($this->startDate === '') {
            return 0.0;
        }

        $billed = Bill::where('vendor_id', $this->vendor->id)
            ->whereNotNull('journal_entry_id')
            ->where('bill_date', '<', $this->startDate)
            ->sum('total');

        $paid = Payment::where('payable_type', Bill::class)
            ->whereIn('payable_id', $ids)
            ->where('payment_date', '<', $this->startDate)
            ->sum('amount');

        return round((float) $billed - (float) $paid, 2);
    }

    public function getLedgerData(): array
    {
        $ids = $this->billIds();

        $bills = Bill::where('vendor_id', $this->vendor->id)
            ->whereNotNull('journal_entry_id')
            ->when($this->startDate, fn ($q) => $q->where('bill_date', '>=', $this->startDate))
            ->when($this->endDate,   fn ($q) => $q->where('bill_date', '<=', $this->endDate))
            ->orderBy('bill_date')->orderBy('id')
            ->get()
            ->map(fn ($bill) => [
                'date'        => Carbon::parse($bill->bill_date),
                'sort_key'    => Carbon::parse($bill->bill_date)->format('Y-m-d') . '_1_' . str_pad((string) $bill->id, 10, '0', STR_PAD_LEFT),
                'type'        => 'bill',
                'reference'   => $bill->bill_number,
                'description' => 'Bill',
                'status'      => $bill->status->value ?? (string) $bill->status,
                'debit'       => (float) $bill->total,
                'credit'      => 0.0,
                'link'        => route('accounting.bills.show', $bill->id),
            ]);

        $payments = Payment::where('payable_type', Bill::class)
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

        $entries     = $bills->merge($payments)->sortBy('sort_key')->values();
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

        return view('accounting::livewire.vendor-ledger', [
            'ledger' => $this->getLedgerData(),
        ])->layout($layout, ['title' => 'Supplier Ledger — ' . $this->vendor->name]);
    }
}
