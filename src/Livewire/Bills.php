<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Models\{Bill, BillItem, Payment, Vendor};
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class Bills extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public bool $showModal = false;

    public bool $showPayModal = false;

    public ?int $billId = null;

    public ?int $vendor_id = null;

    public string $bill_date = '';

    public string $due_date = '';

    public string $currency = '';

    public string $notes = '';

    public array $items = [];

    public ?int $payingBillId = null;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'bank_transfer';

    public string $pay_reference = '';

    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->bill_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0, 'tax_rate' => 0];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function openCreate(): void
    {
        $this->reset(['billId', 'vendor_id', 'notes', 'items']);
        $this->bill_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->addItem();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'vendor_id'           => 'required|integer',
            'bill_date'           => 'required|date',
            'due_date'            => 'required|date|after_or_equal:bill_date',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function (): void {
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $itemTax = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal += $amount;
                $taxAmount += $itemTax;
            }

            $bill = Bill::create([
                'vendor_id'  => $this->vendor_id,
                'bill_date'  => $this->bill_date,
                'due_date'   => $this->due_date,
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $subtotal + $taxAmount,
                'currency'   => $this->currency,
                'status'     => 'draft',
                'notes'      => $this->notes ?: null,
            ]);

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];

                BillItem::create([
                    'bill_id'     => $bill->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $amount * (($item['tax_rate'] ?? 0) / 100),
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Bill created successfully!');
        $this->showModal = false;
        $this->reset(['billId', 'vendor_id', 'items']);
    }

    public function postBill(int $id): void
    {
        $bill = Bill::findOrFail($id);

        try {
            app(Accounting::class)->postBill($bill);
            $this->dispatch('notify', type: 'success', message: "Bill {$bill->bill_number} approved.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openPayModal(int $id): void
    {
        $bill = Bill::findOrFail($id);
        $this->payingBillId = $id;
        $this->pay_date = now()->format('Y-m-d');
        $this->pay_amount = number_format($bill->balance, 2, '.', '');
        $this->pay_method = 'bank_transfer';
        $this->pay_reference = '';
        $this->showPayModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'   => 'required|date',
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_method' => 'required|string',
        ]);

        $bill = Bill::findOrFail($this->payingBillId);

        DB::transaction(function () use ($bill): void {
            Payment::create([
                'payable_type'   => Bill::class,
                'payable_id'     => $bill->id,
                'payment_date'   => $this->pay_date,
                'amount'         => $this->pay_amount,
                'payment_method' => $this->pay_method,
                'reference'      => $this->pay_reference ?: null,
            ]);

            $bill->increment('paid_amount', $this->pay_amount);
            $bill->refresh();
            $bill->update(['status' => (float) $bill->paid_amount >= (float) $bill->total ? 'settled' : 'partially_settled']);
        });

        $this->dispatch('notify', type: 'success', message: 'Payment recorded!');
        $this->showPayModal = false;
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i): int|float => ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0));
    }

    public function getTaxTotalProperty(): float
    {
        return collect($this->items)->sum(function ($i): float {
            $amount = ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0);

            return $amount * (($i['tax_rate'] ?? 0) / 100);
        });
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxTotal;
    }

    public function render()
    {
        $bills = Bill::query()
            ->with(['vendor'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('bill_number', 'like', '%' . $this->search . '%')
                    ->orWhereHas('vendor', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'));
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('bill_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('bill_date', '<=', $this->dateTo))
            ->latest('bill_date')
            ->paginate(config('accounting.per_page.bills', 15));

        $vendors = Vendor::where('is_active', true)->orderBy('name')->get();

        return view('accounting::livewire.bills', ['bills' => $bills, 'vendors' => $vendors]);
    }
}
