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
    private const DISCOUNT_ACCOUNT_CODES = ['5500', '5501', '5502', '5503'];

    public Bill $bill;

    // Charge modal — delivery/courier/return costs (expense — does not affect AP)
    // JE: DR expense_account / CR Cash or Bank (selected)
    public bool $showChargeModal = false;

    public string $charge_type = '6310';

    public string $charge_amount = '';

    public string $charge_date = '';

    public string $charge_notes = '';

    public string $charge_account_code = '1000';

    // Discount modal — vendor gives a price reduction
    // JE: DR AP (2000) / CR Purchase Discount
    public bool $showDiscountModal = false;

    public string $discount_type = '5500';

    public string $discount_amount = '';

    public string $discount_date = '';

    public string $discount_notes = '';

    public function mount(Bill $bill): void
    {
        $this->bill = $bill;
        $this->charge_date = now()->format('Y-m-d');
        $this->discount_date = now()->format('Y-m-d');
    }

    /**
     * Effective AP = bill total − paid − discounts (Bill::$balance is the canonical
     * calculation, shared with the bill list, ledger, and payment cap).
     * Delivery/courier/return charges are expenses and do not affect AP.
     */
    #[Computed]
    public function availableAp(): float
    {
        return round((float) $this->bill->balance, 2);
    }

    #[Computed]
    public function discountAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::whereIn('code', self::DISCOUNT_ACCOUNT_CODES)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function chargeAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where('type', 'asset')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function openChargeModal(): void
    {
        $this->reset(['charge_amount', 'charge_notes']);
        $this->charge_type = '6310';
        $this->charge_date = now()->format('Y-m-d');
        $this->charge_account_code = config('accounting.accounts.cash', '1000');
        $this->showChargeModal = true;
    }

    public function recordCharge(): void
    {
        $this->validate([
            'charge_type'         => 'required|in:4210,4220,6310,6320,6330,6340',
            'charge_amount'       => 'required|numeric|min:0.01',
            'charge_date'         => 'required|date',
            'charge_account_code' => 'required|string',
        ]);

        $chargeAccount = Account::where('code', $this->charge_type)->where('is_active', true)->first();

        if (!$chargeAccount) {
            $this->dispatch('notify', type: 'error', message: "Account {$this->charge_type} not found. Please run the accounting seeder.");

            return;
        }

        $paymentAccount = Account::where('code', $this->charge_account_code)->where('is_active', true)->first();

        if (!$paymentAccount) {
            $this->dispatch('notify', type: 'error', message: "Payment account {$this->charge_account_code} not found or inactive.");

            return;
        }

        try {
            DB::transaction(function () use ($chargeAccount, $paymentAccount): void {
                $amount = round((float) $this->charge_amount, 2);
                $currency = $this->bill->currency ?? config('accounting.base_currency', 'BDT');
                $paymentMethod = str_starts_with($paymentAccount->code, '11') ? 'bank_transfer' : 'cash';

                $expense = Expense::create([
                    'chargeable_type'      => Bill::class,
                    'chargeable_id'        => $this->bill->id,
                    'account_id'           => $chargeAccount->id,
                    'expense_date'         => $this->charge_date,
                    'subtotal'             => $amount,
                    'tax_amount'           => 0,
                    'total'                => $amount,
                    'paid_amount'          => $amount,
                    'currency'             => $currency,
                    'status'               => 'paid',
                    'payment_method'       => $paymentMethod,
                    'payment_account_code' => $paymentAccount->code,
                    'reference'            => $this->bill->bill_number,
                    'notes'                => $this->charge_notes ?: null,
                ]);

                // DR expense (4210/4220/6310/6320/6330/6340) / CR selected cash or bank account — does not affect AP
                $entry = app(Accounting::class)->createJournalEntry([
                    'date'        => $this->charge_date,
                    'reference'   => $this->bill->bill_number,
                    'type'        => 'general',
                    'description' => $chargeAccount->name . ' — ' . $this->bill->bill_number,
                    'currency'    => $currency,
                    'lines'       => [
                        ['account_id' => $chargeAccount->id,  'type' => 'debit',  'amount' => $amount, 'description' => $chargeAccount->name . ' for ' . $this->bill->bill_number],
                        ['account_id' => $paymentAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => $paymentAccount->name . ' payment for ' . $chargeAccount->name],
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
        $this->discount_type = '5500';
        $this->discount_date = now()->format('Y-m-d');
        $this->showDiscountModal = true;
    }

    public function recordDiscount(): void
    {
        $availableAp = $this->availableAp();

        $this->validate([
            'discount_type'   => 'required|in:' . implode(',', self::DISCOUNT_ACCOUNT_CODES),
            'discount_amount' => ['required', 'numeric', 'min:0.01', "max:{$availableAp}"],
            'discount_date'   => 'required|date',
        ], [
            'discount_amount.max' => "Discount cannot exceed the available AP balance of {$availableAp}.",
        ]);

        $discountAccount = Account::where('code', $this->discount_type)->where('is_active', true)->first();

        if (!$discountAccount) {
            $this->dispatch('notify', type: 'error', message: "Discount account {$this->discount_type} not found. Please run the accounting seeder.");

            return;
        }

        $apAccount = Account::where('code', '2000')->where('is_active', true)->first();

        if (!$apAccount) {
            $this->dispatch('notify', type: 'error', message: 'Accounts Payable account (2000) not found.');

            return;
        }

        try {
            DB::transaction(function () use ($discountAccount, $apAccount): void {
                $amount = round((float) $this->discount_amount, 2);
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
