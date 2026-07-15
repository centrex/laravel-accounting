<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Carbon\Carbon;
use Centrex\Accounting\Models\{CreditMemo, Customer, Expense, Invoice, Payment};
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CustomerLedger extends Component
{
    // Expense account codes that reduce AR (manual discounts), regardless of
    // payment_method. 'ar_deduction' additionally covers charges netted off AR at
    // payment time, whose account code varies (shipping/delivery accounts).
    // Sale returns are no longer in this set — they flow through CreditMemo rows below.
    private const AR_REDUCING_ACCOUNT_CODES = Invoice::AR_REDUCING_ACCOUNT_CODES;

    public Customer $customer;

    public string $startDate = '';

    public string $endDate = '';

    protected array $queryString = ['startDate', 'endDate'];

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
        $this->startDate = $this->startDate ?: now()->startOfYear()->format('Y-m-d');
        $this->endDate = $this->endDate ?: now()->format('Y-m-d');
    }

    private function invoiceIds(): \Illuminate\Support\Collection
    {
        return Invoice::where('customer_id', $this->customer->id)->pluck('id');
    }

    private function arReducingExpensesQuery(\Illuminate\Support\Collection $ids): \Illuminate\Database\Eloquent\Builder
    {
        return Expense::with('account')
            ->where('chargeable_type', Invoice::class)
            ->whereIn('chargeable_id', $ids)
            ->where(fn ($q) => $q->where('payment_method', 'ar_deduction')
                ->orWhereHas('account', fn ($q2) => $q2->whereIn('code', self::AR_REDUCING_ACCOUNT_CODES)));
    }

    /** Issued (non-draft, non-void) credit memos for this customer — they credit AR like a payment. */
    private function creditMemosQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return CreditMemo::where('customer_id', $this->customer->id)
            ->whereNotIn('status', ['draft', 'void']);
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

        $credited = $this->arReducingExpensesQuery($ids)
            ->where('expense_date', '<', $this->startDate)
            ->sum('total');

        $memoCredits = $this->creditMemosQuery()
            ->where('credit_memo_date', '<', $this->startDate)
            ->sum('total');

        // Cash refunds debit AR back after the memo credited it
        $refunds = Payment::where('payable_type', CreditMemo::class)
            ->whereIn('payable_id', $this->creditMemosQuery()->pluck('id'))
            ->where('payment_date', '<', $this->startDate)
            ->sum('amount');

        return round((float) $invoiced - (float) $paid - (float) $credited - (float) $memoCredits + (float) $refunds, 2);
    }

    public function getLedgerData(): array
    {
        $ids = $this->invoiceIds();

        $invoices = Invoice::where('customer_id', $this->customer->id)
            ->whereNotNull('journal_entry_id')
            ->when($this->startDate, fn ($q) => $q->where('invoice_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->where('invoice_date', '<=', $this->endDate))
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
            ->when($this->endDate, fn ($q) => $q->where('payment_date', '<=', $this->endDate))
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

        $credits = $this->arReducingExpensesQuery($ids)
            ->when($this->startDate, fn ($q) => $q->where('expense_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->where('expense_date', '<=', $this->endDate))
            ->orderBy('expense_date')->orderBy('id')
            ->get()
            ->map(fn ($exp) => [
                'date'        => Carbon::parse($exp->expense_date),
                'sort_key'    => Carbon::parse($exp->expense_date)->format('Y-m-d') . '_1_' . str_pad((string) $exp->id, 10, '0', STR_PAD_LEFT) . '_5',
                'type'        => 'credit',
                'reference'   => $exp->reference ?? $exp->expense_number,
                'description' => $exp->account?->name ?? 'Credit',
                'status'      => null,
                'debit'       => 0.0,
                'credit'      => (float) $exp->total,
                'link'        => null,
            ]);

        $creditMemos = $this->creditMemosQuery()
            ->when($this->startDate, fn ($q) => $q->where('credit_memo_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->where('credit_memo_date', '<=', $this->endDate))
            ->orderBy('credit_memo_date')->orderBy('id')
            ->get()
            ->map(fn ($memo) => [
                'date'        => Carbon::parse($memo->credit_memo_date),
                'sort_key'    => Carbon::parse($memo->credit_memo_date)->format('Y-m-d') . '_1_' . str_pad((string) $memo->id, 10, '0', STR_PAD_LEFT) . '_6',
                'type'        => 'credit',
                'reference'   => $memo->credit_memo_number,
                'description' => 'Credit Memo' . ($memo->reason ? ' — ' . $memo->reason : ''),
                'status'      => $memo->status->value,
                'debit'       => 0.0,
                'credit'      => (float) $memo->total,
                'link'        => route('accounting.credit-memos.show', $memo->id),
            ]);

        // Cash refunds against credit memos debit AR back out
        $refunds = Payment::where('payable_type', CreditMemo::class)
            ->whereIn('payable_id', $this->creditMemosQuery()->pluck('id'))
            ->when($this->startDate, fn ($q) => $q->where('payment_date', '>=', $this->startDate))
            ->when($this->endDate, fn ($q) => $q->where('payment_date', '<=', $this->endDate))
            ->orderBy('payment_date')->orderBy('id')
            ->get()
            ->map(fn ($pmt) => [
                'date'        => Carbon::parse($pmt->payment_date),
                'sort_key'    => Carbon::parse($pmt->payment_date)->format('Y-m-d') . '_2_' . str_pad((string) $pmt->id, 10, '0', STR_PAD_LEFT) . '_7',
                'type'        => 'refund',
                'reference'   => $pmt->payment_number,
                'description' => 'Refund — ' . ucwords(str_replace('_', ' ', (string) ($pmt->payment_method ?? ''))),
                'status'      => null,
                'debit'       => (float) $pmt->amount,
                'credit'      => 0.0,
                'link'        => null,
            ]);

        $entries = $invoices->merge($payments)->merge($credits)->merge($creditMemos)->merge($refunds)->sortBy('sort_key')->values();
        $opening = $this->openingBalance($ids);
        $balance = $opening;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $rows = [];

        foreach ($entries as $entry) {
            $balance += $entry['debit'] - $entry['credit'];
            $totalDebit += $entry['debit'];
            $totalCredit += $entry['credit'];
            $rows[] = array_merge($entry, ['balance' => $balance]);
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
