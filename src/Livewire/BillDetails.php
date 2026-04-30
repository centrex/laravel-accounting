<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, Bill, Expense};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BillDetails extends Component
{
    public Bill $bill;

    // Charge modal — delivery/courier/return costs (expense — does not affect AP)
    // JE: DR expense_account / CR Cash (1000)
    public bool $showChargeModal = false;

    public string $charge_type = '6310';

    public string $charge_amount = '';

    public string $charge_date = '';

    public string $charge_notes = '';

    // Discount modal — vendor gives a price reduction
    // JE: DR AP (2000) / CR Purchase Discount (5500)
    public bool $showDiscountModal = false;

    public string $discount_amount = '';

    public string $discount_date = '';

    public string $discount_notes = '';

    public function mount(Bill $bill): void
    {
        $this->bill = $bill;
        $this->charge_date   = now()->format('Y-m-d');
        $this->discount_date = now()->format('Y-m-d');
    }

    /**
     * Effective AP = bill total − paid − discounts (5500).
     * Delivery/courier/return charges are expenses and do not affect AP.
     */
    #[Computed]
    public function availableAp(): float
    {
        $discounts = Expense::with('account')
            ->where('chargeable_type', Bill::class)
            ->where('chargeable_id', $this->bill->id)
            ->get()
            ->filter(fn ($e) => $e->account?->code === '5500')
            ->sum('total');

        return round(
            (float) $this->bill->total - (float) $this->bill->paid_amount - (float) $discounts,
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
                $currency = $this->bill->currency ?? config('accounting.base_currency', 'BDT');

                $expense = Expense::create([
                    'chargeable_type' => Bill::class,
                    'chargeable_id'   => $this->bill->id,
                    'account_id'      => $chargeAccount->id,
                    'expense_date'    => $this->charge_date,
                    'subtotal'        => $amount,
                    'tax_amount'      => 0,
                    'total'           => $amount,
                    'paid_amount'     => $amount,
                    'currency'        => $currency,
                    'status'          => 'paid',
                    'payment_method'  => 'cash',
                    'reference'       => $this->bill->bill_number,
                    'notes'           => $this->charge_notes ?: null,
                ]);

                // DR expense (6310/6320/6330/6340) / CR Cash (1000) — does not affect AP
                $entry = app(Accounting::class)->createJournalEntry([
                    'date'        => $this->charge_date,
                    'reference'   => $this->bill->bill_number,
                    'type'        => 'general',
                    'description' => $chargeAccount->name . ' — ' . $this->bill->bill_number,
                    'currency'    => $currency,
                    'lines'       => [
                        ['account_id' => $chargeAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => $chargeAccount->name . ' for ' . $this->bill->bill_number],
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
        $availableAp = $this->availableAp();

        $this->validate([
            'discount_amount' => ['required', 'numeric', 'min:0.01', "max:{$availableAp}"],
            'discount_date'   => 'required|date',
        ], [
            'discount_amount.max' => "Discount cannot exceed the available AP balance of {$availableAp}.",
        ]);

        $discountAccount = Account::where('code', '5500')->where('is_active', true)->first();

        if (!$discountAccount) {
            $this->dispatch('notify', type: 'error', message: 'Purchase Discount account (5500) not found. Please run the accounting seeder.');

            return;
        }

        $apAccount = Account::where('code', '2000')->where('is_active', true)->first();

        if (!$apAccount) {
            $this->dispatch('notify', type: 'error', message: 'Accounts Payable account (2000) not found.');

            return;
        }

        try {
            DB::transaction(function () use ($discountAccount, $apAccount): void {
                $amount   = round((float) $this->discount_amount, 2);
                $currency = $this->bill->currency ?? config('accounting.base_currency', 'BDT');

                $expense = Expense::create([
                    'chargeable_type' => Bill::class,
                    'chargeable_id'   => $this->bill->id,
                    'account_id'      => $discountAccount->id,
                    'expense_date'    => $this->discount_date,
                    'subtotal'        => $amount,
                    'tax_amount'      => 0,
                    'total'           => $amount,
                    'paid_amount'     => $amount,
                    'currency'        => $currency,
                    'status'          => 'paid',
                    'payment_method'  => 'cash',
                    'reference'       => $this->bill->bill_number,
                    'notes'           => $this->discount_notes ?: null,
                ]);

                // DR AP (2000) / CR Purchase Discount (5500)
                $entry = app(Accounting::class)->createJournalEntry([
                    'date'        => $this->discount_date,
                    'reference'   => $this->bill->bill_number,
                    'type'        => 'general',
                    'description' => 'Purchase Discount — ' . $this->bill->bill_number,
                    'currency'    => $currency,
                    'lines'       => [
                        ['account_id' => $apAccount->id,       'type' => 'debit',  'amount' => $amount, 'description' => 'Discount applied to AP'],
                        ['account_id' => $discountAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Purchase Discount for ' . $this->bill->bill_number],
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
        $this->bill->load([
            'vendor',
            'items',
            'payments.journalEntry.lines.account',
            'journalEntry.lines.account',
            'expenses.account',
            'expenses.journalEntry.lines.account',
        ]);

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('accounting::livewire.bill-details')
            ->layout($layout, ['title' => __('Bill Details')]);
    }
}
