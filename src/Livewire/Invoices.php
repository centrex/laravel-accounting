<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\{ShowsAuditTrail, WithCurrency};
use Centrex\Accounting\Models\{Account, Customer, Invoice, InvoiceItem};
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\{Computed, On};
use Livewire\Component;

class Invoices extends Component
{
    use ShowsAuditTrail;
    use WithCurrency;

    public bool $showModal = false;

    public bool $showPayModal = false;

    public ?int $invoiceId = null;

    public ?int $customer_id = null;

    public string $invoice_date = '';

    public string $due_date = '';

    public string $notes = '';

    public array $items = [];

    public ?int $payingInvoiceId = null;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'bank_transfer';

    public string $pay_account_code = '';

    public string $pay_reference = '';

    public string $pay_notes = '';

    // Optional shipping/handling charge netted off AR alongside the cash received
    public string $pay_charge_amount = '';

    public string $pay_charge_account_code = '';

    public function mount(): void
    {
        $this->invoice_date = now()->format('Y-m-d');
        $this->due_date = now()->addDays(30)->format('Y-m-d');
        $this->addItem();

        $this->handleRequestedAction();
    }

    protected function handleRequestedAction(): void
    {
        if (!request()->filled('invoice')) {
            return;
        }

        $invoiceId = (int) request()->query('invoice');

        if ($invoiceId <= 0) {
            return;
        }

        $invoice = Invoice::query()->find($invoiceId);

        if (!$invoice) {
            return;
        }

        $action = (string) request()->query('action', '');

        if (in_array($action, ['post', 'post-and-pay'], true) && ($invoice->status->value ?? (string) $invoice->status) === 'draft') {
            try {
                app(Accounting::class)->postInvoice($invoice);
                $invoice->refresh();
                $this->dispatch('notify', type: 'success', message: "Invoice {$invoice->invoice_number} posted.");
            } catch (\Throwable $e) {
                $this->dispatch('notify', type: 'error', message: $e->getMessage());

                return;
            }
        }

        if (in_array($action, ['pay', 'post-and-pay'], true)) {
            $this->openPayModal($invoiceId);
        }
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
                'currency'        => self::getCurrency(),
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
        $this->dispatch('invoice-table:refresh');
    }

    #[On('invoice-table:post')]
    public function postInvoice(int $id): void
    {
        $invoice = Invoice::findOrFail($id);

        try {
            app(Accounting::class)->postInvoice($invoice);
            $this->dispatch('notify', type: 'success', message: "Invoice {$invoice->invoice_number} posted.");
            $this->dispatch('invoice-table:refresh');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    #[On('invoice-table:audit')]
    public function openInvoiceAuditTrail(int $id): void
    {
        $invoice = Invoice::findOrFail($id);
        $this->openAuditTrail($invoice::class, $invoice->id, $invoice->invoice_number);
    }

    #[Computed]
    public function paymentAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where('type', 'asset')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function chargeAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::whereIn('code', ['4210', '4220', '6310', '6320', '6330', '6340'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function payingInvoice(): ?Invoice
    {
        return $this->payingInvoiceId ? Invoice::find($this->payingInvoiceId) : null;
    }

    #[On('invoice-table:pay')]
    public function openPayModal(int $id): void
    {
        $invoice = Invoice::findOrFail($id);
        $this->payingInvoiceId = $id;
        $this->pay_date = now()->format('Y-m-d');
        $this->pay_amount = number_format($invoice->balance, 2, '.', '');
        $this->pay_method = 'bank_transfer';
        $this->pay_account_code = '';
        $this->pay_reference = '';
        $this->pay_notes = '';
        $this->pay_charge_amount = '';
        $this->pay_charge_account_code = '';
        $this->showPayModal = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'                => 'required|date',
            'pay_amount'              => 'required|numeric|min:0.01',
            'pay_method'              => 'required|string',
            'pay_account_code'        => 'required|string',
            'pay_charge_amount'       => 'nullable|numeric|min:0',
            'pay_charge_account_code' => 'required_with:pay_charge_amount|string',
        ]);

        $invoice = Invoice::findOrFail($this->payingInvoiceId);

        if ((float) $this->pay_amount > $invoice->balance + 0.005) {
            $this->addError('pay_amount', 'Amount cannot exceed the outstanding balance of ' . $invoice->currency . ' ' . number_format($invoice->balance, 2) . '.');

            return;
        }

        $chargeAmount = (float) ($this->pay_charge_amount ?: 0);

        try {
            app(Accounting::class)->recordInvoicePayment($invoice, [
                'date'                => $this->pay_date,
                'amount'              => $this->pay_amount,
                'method'              => $this->pay_method,
                'account_code'        => $this->pay_account_code,
                'reference'           => $this->pay_reference ?: null,
                'notes'               => $this->pay_notes ?: null,
                'charge_amount'       => $chargeAmount,
                'charge_account_code' => $chargeAmount > 0 ? $this->pay_charge_account_code : null,
            ]);

            $this->dispatch('notify', type: 'success', message: 'Payment recorded successfully!');
            $this->showPayModal = false;
            $this->dispatch('invoice-table:refresh');
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

    public function render(): \Illuminate\Contracts\View\View
    {
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.invoices', ['customers' => $customers])->layout($layout, ['title' => __('Invoices')]);
    }
}
