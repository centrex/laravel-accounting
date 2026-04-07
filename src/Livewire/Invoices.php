<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Livewire;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Models\{Customer, Invoice, InvoiceItem};
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class Invoices extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    // Modal state
    public bool $showModal = false;

    public bool $showPayModal = false;

    // Invoice form
    public ?int $invoiceId = null;

    public ?int $customer_id = null;

    public string $invoice_date = '';

    public string $due_date = '';

    public string $currency = '';

    public string $notes = '';

    public array $items = [];

    // Payment form
    public ?int $payingInvoiceId = null;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'bank_transfer';

    public string $pay_reference = '';

    public string $pay_notes = '';

    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->invoice_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'description' => '',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => 0,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function openCreate(): void
    {
        $this->reset(['invoiceId', 'customer_id', 'notes', 'items']);
        $this->invoice_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->addItem();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'customer_id'         => 'required|integer',
            'invoice_date'        => 'required|date',
            'due_date'            => 'required|date|after_or_equal:invoice_date',
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

            $invoice = Invoice::create([
                'customer_id'     => $this->customer_id,
                'invoice_date'    => $this->invoice_date,
                'due_date'        => $this->due_date,
                'subtotal'        => $subtotal,
                'tax_amount'      => $taxAmount,
                'discount_amount' => 0,
                'total'           => $subtotal + $taxAmount,
                'currency'        => $this->currency,
                'status'          => 'draft',
                'notes'           => $this->notes ?: null,
            ]);

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax = $amount * (($item['tax_rate'] ?? 0) / 100);

                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Invoice created successfully!');
        $this->showModal = false;
        $this->reset(['invoiceId', 'customer_id', 'items']);
    }

    public function postInvoice(int $id): void
    {
        $invoice = Invoice::findOrFail($id);

        try {
            app(Accounting::class)->postInvoice($invoice);
            $this->dispatch('notify', type: 'success', message: "Invoice {$invoice->invoice_number} posted.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openPayModal(int $id): void
    {
        $invoice = Invoice::findOrFail($id);
        $this->payingInvoiceId = $id;
        $this->pay_date = now()->format('Y-m-d');
        $this->pay_amount = number_format($invoice->balance, 2, '.', '');
        $this->pay_method = 'bank_transfer';
        $this->pay_reference = '';
        $this->pay_notes = '';
        $this->showPayModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'   => 'required|date',
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_method' => 'required|string',
        ]);

        $invoice = Invoice::findOrFail($this->payingInvoiceId);

        try {
            app(Accounting::class)->recordInvoicePayment($invoice, [
                'date'      => $this->pay_date,
                'amount'    => $this->pay_amount,
                'method'    => $this->pay_method,
                'reference' => $this->pay_reference ?: null,
                'notes'     => $this->pay_notes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: 'Payment recorded successfully!');
            $this->showPayModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
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
        $invoices = Invoice::query()
            ->with(['customer'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('invoice_number', 'like', '%' . $this->search . '%')
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'));
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('invoice_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('invoice_date', '<=', $this->dateTo))
            ->latest('invoice_date')
            ->paginate(config('accounting.per_page.invoices', 15));

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.invoices', ['invoices' => $invoices, 'customers' => $customers])->layout($layout, ['title' => __('Invoices')]);
    }
}
