<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, Expense, Invoice};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class InvoiceDetails extends Component
{
    public Invoice $invoice;

    // Charge modal (delivery / COD — DR expense / CR Cash, does not affect AR)
    public bool $showChargeModal = false;

    public string $charge_type = '6310';

    public string $charge_amount = '';

    public string $charge_date = '';

    public string $charge_notes = '';

    // Discount modal (DR Sales Discount 6130 / CR AR)
    public bool $showDiscountModal = false;

    public string $discount_amount = '';

    public string $discount_date = '';

    public string $discount_notes = '';

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
        $this->charge_date   = now()->format('Y-m-d');
        $this->discount_date = now()->format('Y-m-d');
    }

    /**
     * Effective AR = invoice total − paid − discounts (6130).
     * Delivery/COD/return charges are expenses and do not affect AR.
     */
    #[Computed]
    public function availableAr(): float
    {
        $discounts = Expense::with('account')
            ->where('chargeable_type', Invoice::class)
            ->where('chargeable_id', $this->invoice->id)
            ->get()
            ->filter(fn ($e) => $e->account?->code === '6130')
            ->sum('total');

        return round(
            (float) $this->invoice->total - (float) $this->invoice->paid_amount - (float) $discounts,
            2,
        );
    }

    public function openChargeModal(): void
    {
        $this->reset(['charge_amount', 'charge_notes']);
        $this->charge_type = '6310';
        $this->charge_date = now()->format('Y-m-d');
        $this->showChargeModal = true;
    }

    public function recordCharge(): void
    {
        $this->validate([
            'charge_type'   => 'required|in:6310,6320,6330,6340',
            'charge_amount' => 'required|numeric|min:0.01',
            'charge_date'   => 'required|date',
        ]);

        $chargeAccount = Account::where('code', $this->charge_type)->where('is_active', true)->first();

        if (!$chargeAccount) {
            $this->dispatch('notify', type: 'error', message: "Account {$this->charge_type} not found. Please run the accounting seeder.");

            return;
        }

        $cashAccount = Account::where('code', '1000')->where('is_active', true)->first();

        if (!$cashAccount) {
            $this->dispatch('notify', type: 'error', message: 'Cash account (1000) not found.');

            return;
        }

        try {
            DB::transaction(function () use ($chargeAccount, $cashAccount): void {
                $amount   = round((float) $this->charge_amount, 2);
                $currency = $this->invoice->currency ?? config('accounting.base_currency', 'BDT');

                $expense = Expense::create([
                    'chargeable_type' => Invoice::class,
                    'chargeable_id'   => $this->invoice->id,
                    'account_id'      => $chargeAccount->id,
                    'expense_date'    => $this->charge_date,
                    'subtotal'        => $amount,
                    'tax_amount'      => 0,
                    'total'           => $amount,
                    'paid_amount'     => $amount,
                    'currency'        => $currency,
                    'status'          => 'paid',
                    'payment_method'  => 'cash',
                    'reference'       => $this->invoice->invoice_number,
                    'notes'           => $this->charge_notes ?: null,
                ]);

                // DR expense (6310/6320/6330/6340) / CR Cash (1000) — does not affect AR
                $entry = app(Accounting::class)->createJournalEntry([
                    'date'        => $this->charge_date,
                    'reference'   => $this->invoice->invoice_number,
                    'type'        => 'general',
                    'description' => $chargeAccount->name . ' — ' . $this->invoice->invoice_number,
                    'currency'    => $currency,
                    'lines'       => [
                        ['account_id' => $chargeAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => $chargeAccount->name . ' for ' . $this->invoice->invoice_number],
                        ['account_id' => $cashAccount->id,   'type' => 'credit', 'amount' => $amount, 'description' => 'Cash payment for ' . $chargeAccount->name],
                    ],
                ]);

                $entry->post();
                $expense->update(['journal_entry_id' => $entry->id]);
            });

            $this->dispatch('notify', type: 'success', message: 'Charge recorded successfully.');
            $this->showChargeModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openDiscountModal(): void
    {
        $this->reset(['discount_amount', 'discount_notes']);
        $this->discount_date = now()->format('Y-m-d');
        $this->showDiscountModal = true;
    }

    public function recordDiscount(): void
    {
        $availableAr = $this->availableAr();

        $this->validate([
            'discount_amount' => ['required', 'numeric', 'min:0.01', "max:{$availableAr}"],
            'discount_date'   => 'required|date',
        ], [
            'discount_amount.max' => "Discount cannot exceed the available AR balance of {$availableAr}.",
        ]);

        $discountAccount = Account::where('code', '6130')->where('is_active', true)->first();

        if (!$discountAccount) {
            $this->dispatch('notify', type: 'error', message: 'Sales Discount account (6130) not found. Please run the accounting seeder.');

            return;
        }

        $arAccount = Account::where('code', '1200')->where('is_active', true)->first();

        if (!$arAccount) {
            $this->dispatch('notify', type: 'error', message: 'Accounts Receivable account (1200) not found.');

            return;
        }

        try {
            DB::transaction(function () use ($discountAccount, $arAccount): void {
                $amount   = round((float) $this->discount_amount, 2);
                $currency = $this->invoice->currency ?? config('accounting.base_currency', 'BDT');

                $expense = Expense::create([
                    'chargeable_type' => Invoice::class,
                    'chargeable_id'   => $this->invoice->id,
                    'account_id'      => $discountAccount->id,
                    'expense_date'    => $this->discount_date,
                    'subtotal'        => $amount,
                    'tax_amount'      => 0,
                    'total'           => $amount,
                    'paid_amount'     => $amount,
                    'currency'        => $currency,
                    'status'          => 'paid',
                    'payment_method'  => 'cash',
                    'reference'       => $this->invoice->invoice_number,
                    'notes'           => $this->discount_notes ?: null,
                ]);

                // DR Sales Discount (6130) / CR AR (1200)
                $entry = app(Accounting::class)->createJournalEntry([
                    'date'        => $this->discount_date,
                    'reference'   => $this->invoice->invoice_number,
                    'type'        => 'general',
                    'description' => 'Sales Discount — ' . $this->invoice->invoice_number,
                    'currency'    => $currency,
                    'lines'       => [
                        ['account_id' => $discountAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => 'Sales Discount for ' . $this->invoice->invoice_number],
                        ['account_id' => $arAccount->id,       'type' => 'credit', 'amount' => $amount, 'description' => 'Discount applied to AR'],
                    ],
                ]);

                $entry->post();
                $expense->update(['journal_entry_id' => $entry->id]);
            });

            $this->dispatch('notify', type: 'success', message: 'Discount recorded successfully.');
            $this->showDiscountModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        // Always re-load relations — Livewire re-hydrates model properties
        // without nested relations on each action request.
        $this->invoice->load([
            'customer',
            'items',
            'payments.journalEntry.lines.account',
            'journalEntry.lines.account',
            'expenses.account',
            'expenses.journalEntry.lines.account',
        ]);

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.invoice-details')
            ->layout($layout, ['title' => __('Invoice Details')]);
    }
}
