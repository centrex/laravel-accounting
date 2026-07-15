<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Enums\CreditMemoStatus;
use Centrex\Accounting\Models\{CreditMemo, Invoice};
use Illuminate\Contracts\View\View;
use Livewire\{Component, WithPagination};

class CreditMemos extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    // Create modal
    public bool $showModal = false;

    public ?int $invoice_id = null;

    public string $memo_date = '';

    public string $reason = '';

    public string $subtotal = '';

    public string $tax_amount = '';

    public string $notes = '';

    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->memo_date = now()->format('Y-m-d');

        // Deep link from InvoiceDetails: /accounting/credit-memos?invoice=<id>&action=create
        if (request()->query('action') === 'create') {
            $invoiceId = (int) request()->query('invoice', 0);

            if ($invoiceId > 0 && Invoice::whereNotNull('journal_entry_id')->whereKey($invoiceId)->exists()) {
                $this->invoice_id = $invoiceId;
            }

            $this->showModal = true;
        }
    }

    public function openCreate(): void
    {
        $this->reset(['invoice_id', 'reason', 'subtotal', 'tax_amount', 'notes']);
        $this->memo_date = now()->format('Y-m-d');
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'invoice_id' => 'required|exists:' . (new Invoice())->getTable() . ',id',
            'memo_date'  => 'required|date',
            'reason'     => 'nullable|string|max:255',
            'subtotal'   => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
        ]);

        $invoice = Invoice::findOrFail($this->invoice_id);

        try {
            $memo = app(Accounting::class)->createCreditMemo($invoice, [
                'date'       => $this->memo_date,
                'reason'     => $this->reason ?: null,
                'subtotal'   => (float) $this->subtotal,
                'tax_amount' => (float) ($this->tax_amount ?: 0),
                'notes'      => $this->notes ?: null,
            ]);

            $this->dispatch('notify', type: 'success', message: "{$memo->credit_memo_number} created as draft.");
            $this->showModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function issueMemo(int $id): void
    {
        $memo = CreditMemo::findOrFail($id);

        try {
            app(Accounting::class)->issueCreditMemo($memo);
            $this->dispatch('notify', type: 'success', message: "{$memo->credit_memo_number} issued — AR credited.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function voidMemo(int $id): void
    {
        $memo = CreditMemo::findOrFail($id);

        try {
            app(Accounting::class)->voidCreditMemo($memo);
            $this->dispatch('notify', type: 'warning', message: "{$memo->credit_memo_number} voided.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function render(): View
    {
        $memos = CreditMemo::query()
            ->with(['invoice', 'customer'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $like = '%' . $this->search . '%';
                $q->where('credit_memo_number', 'like', $like)
                    ->orWhere('source_reference', 'like', $like)
                    ->orWhereHas('invoice', fn ($q) => $q->where('invoice_number', 'like', $like))
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', $like)->orWhere('organization_name', 'like', $like));
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('credit_memo_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('credit_memo_date', '<=', $this->dateTo))
            ->latest('created_at')
            ->paginate(config('accounting.per_page.credit_memos', 15));

        // Only posted, non-void invoices can be credited
        $invoices = Invoice::query()
            ->with('customer')
            ->whereNotNull('journal_entry_id')
            ->where('status', '!=', 'void')
            ->latest('invoice_date')
            ->limit(200)
            ->get();

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.credit-memos', [
            'memos'    => $memos,
            'invoices' => $invoices,
            'statuses' => CreditMemoStatus::cases(),
        ])->layout($layout, ['title' => __('Credit Memos')]);
    }
}
