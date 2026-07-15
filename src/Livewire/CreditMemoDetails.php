<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Enums\CreditMemoStatus;
use Centrex\Accounting\Models\{Account, CreditMemo};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreditMemoDetails extends Component
{
    public CreditMemo $creditMemo;

    // Refund modal (DR AR / CR Cash or Bank — pays the credit back out in cash)
    public bool $showRefundModal = false;

    public string $refund_amount = '';

    public string $refund_date = '';

    public string $refund_method = 'cash';

    public string $refund_account_code = '1000';

    public string $refund_reference = '';

    public string $refund_notes = '';

    public function mount(CreditMemo $creditMemo): void
    {
        $this->creditMemo = $creditMemo;
        $this->refund_date = now()->format('Y-m-d');
    }

    /** Asset accounts (cash 10xx / bank 11xx) the refund can be paid from. */
    #[Computed]
    public function refundAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where('type', 'asset')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function issueMemo(): void
    {
        try {
            app(Accounting::class)->issueCreditMemo($this->creditMemo);
            $this->dispatch('notify', type: 'success', message: "{$this->creditMemo->credit_memo_number} issued — AR credited.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function voidMemo(): void
    {
        try {
            app(Accounting::class)->voidCreditMemo($this->creditMemo);
            $this->dispatch('notify', type: 'warning', message: "{$this->creditMemo->credit_memo_number} voided.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openRefundModal(): void
    {
        $this->reset(['refund_reference', 'refund_notes']);
        $this->refund_amount = number_format($this->creditMemo->refundable_amount, 2, '.', '');
        $this->refund_date = now()->format('Y-m-d');
        $this->refund_method = 'cash';
        $this->refund_account_code = config('accounting.accounts.cash', '1000');
        $this->showRefundModal = true;
    }

    public function recordRefund(): void
    {
        $refundable = $this->creditMemo->refundable_amount;

        $this->validate([
            'refund_amount'       => ['required', 'numeric', 'min:0.01', "max:{$refundable}"],
            'refund_date'         => 'required|date',
            'refund_method'       => 'required|in:cash,bank_transfer,check,card,mobile_banking,other',
            'refund_account_code' => 'required|string',
        ], [
            'refund_amount.max' => "Refund cannot exceed the remaining credit of {$refundable}.",
        ]);

        try {
            $payment = app(Accounting::class)->recordCreditMemoRefund($this->creditMemo, [
                'date'         => $this->refund_date,
                'amount'       => (float) $this->refund_amount,
                'method'       => $this->refund_method,
                'account_code' => $this->refund_account_code,
                'reference'    => $this->refund_reference ?: null,
                'notes'        => $this->refund_notes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: "Refund {$payment->payment_number} recorded.");
            $this->showRefundModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        // Always re-load relations — Livewire re-hydrates model properties
        // without nested relations on each action request.
        $this->creditMemo->load([
            'invoice',
            'customer',
            'journalEntry.lines.account',
            'payments.journalEntry.lines.account',
        ]);

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.credit-memo-details', [
            'canRefund' => in_array($this->creditMemo->status, [CreditMemoStatus::ISSUED, CreditMemoStatus::PARTIALLY_REFUNDED], true)
                && $this->creditMemo->refundable_amount > 0,
        ])->layout($layout, ['title' => __('Credit Memo Details')]);
    }
}
