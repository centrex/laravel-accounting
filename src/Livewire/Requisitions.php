<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\{ShowsAuditTrail, WithCurrency};
use Centrex\Accounting\Enums\{RequisitionStatus, RequisitionType};
use Centrex\Accounting\Models\{Account, Requisition, RequisitionItem, Vendor};
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class Requisitions extends Component
{
    use WithCurrency;
    use ShowsAuditTrail;
    use WithPagination;

    // Filters
    public string $search       = '';
    public string $typeFilter   = '';
    public string $statusFilter = '';
    public string $dateFrom     = '';
    public string $dateTo       = '';

    // Modal state
    public bool $showModal       = false;
    public bool $showDetailModal = false;
    public bool $showRejectModal = false;

    // Form fields
    public ?int $requisitionId   = null;
    public string $type          = 'purchase';
    public string $title         = '';
    public string $description   = '';
    public ?int $vendor_id       = null;
    public ?int $account_id      = null;
    public string $requested_by  = '';
    public string $requested_date = '';
    public string $required_date  = '';
    public string $notes          = '';
    public array $items           = [];

    // Reject modal
    public ?int $rejectingId      = null;
    public string $rejectionReason = '';

    // Detail modal
    public ?int $viewingId = null;

    protected array $queryString = ['search', 'typeFilter', 'statusFilter'];

    public function mount(): void
    {
        $this->requested_date = now()->format('Y-m-d');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']);
    }

    public function openCreate(): void
    {
        $this->reset(['requisitionId', 'title', 'description', 'vendor_id', 'account_id',
            'requested_by', 'required_date', 'notes', 'items', 'rejectionReason']);
        $this->type = 'purchase';
        $this->requested_date = now()->format('Y-m-d');
        $this->addItem();
        $this->showModal = true;
    }

    public function updatedType(): void
    {
        $this->vendor_id = null;
        $this->account_id = null;
    }

    public function openDetail(int $id): void
    {
        $this->viewingId = $id;
        $this->showDetailModal = true;
    }

    public function save(): void
    {
        $rules = [
            'title'               => 'required|string|max:255',
            'type'                => 'required|in:purchase,expense',
            'requested_date'      => 'required|date',
            'required_date'       => 'nullable|date|after_or_equal:requested_date',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.0001',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ];

        if ($this->type === 'purchase') {
            $rules['vendor_id'] = 'nullable|exists:' . (new Vendor())->getTable() . ',id';
        } else {
            $rules['account_id'] = 'nullable|exists:' . (new Account())->getTable() . ',id';
        }

        $this->validate($rules);

        DB::transaction(function (): void {
            $total = $this->subtotal;

            $req = Requisition::create([
                'type'           => $this->type,
                'title'          => $this->title,
                'description'    => $this->description ?: null,
                'vendor_id'      => $this->type === 'purchase' ? $this->vendor_id : null,
                'account_id'     => $this->type === 'expense' ? $this->account_id : null,
                'requested_by'   => $this->requested_by ?: null,
                'requested_date' => $this->requested_date,
                'required_date'  => $this->required_date ?: null,
                'total_amount'   => $total,
                'currency'       => self::getCurrency(),
                'notes'          => $this->notes ?: null,
                'status'         => 'draft',
            ]);

            foreach ($this->items as $item) {
                $qty = (float) $item['quantity'];
                $price = (float) $item['unit_price'];

                RequisitionItem::create([
                    'requisition_id' => $req->id,
                    'description'    => $item['description'],
                    'quantity'       => $qty,
                    'unit_price'     => $price,
                    'total'          => round($qty * $price, 2),
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Requisition created.');
        $this->showModal = false;
    }

    public function submitRequisition(int $id): void
    {
        $req = Requisition::findOrFail($id);

        try {
            app(Accounting::class)->submitRequisition($req);
            $this->dispatch('notify', type: 'success', message: "{$req->requisition_number} submitted for approval.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function approveRequisition(int $id): void
    {
        $req = Requisition::findOrFail($id);

        try {
            app(Accounting::class)->approveRequisition($req, auth()->id());
            $this->dispatch('notify', type: 'success', message: "{$req->requisition_number} approved.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
    }

    public function confirmReject(): void
    {
        $this->validate(['rejectionReason' => 'required|string|min:3|max:500']);

        $req = Requisition::findOrFail($this->rejectingId);

        try {
            app(Accounting::class)->rejectRequisition($req, $this->rejectionReason, auth()->id());
            $this->dispatch('notify', type: 'warning', message: "{$req->requisition_number} rejected.");
            $this->showRejectModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function convertRequisition(int $id): void
    {
        $req = Requisition::findOrFail($id);

        try {
            $accounting = app(Accounting::class);

            if ($req->isPurchase()) {
                $bill = $accounting->convertRequisitionToBill($req);
                $this->dispatch('notify', type: 'success', message: "Converted to Bill {$bill->bill_number}.");
            } else {
                $expense = $accounting->convertRequisitionToExpense($req);
                $this->dispatch('notify', type: 'success', message: "Converted to Expense {$expense->expense_number}.");
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function deleteRequisition(int $id): void
    {
        $req = Requisition::findOrFail($id);

        if (!in_array($req->status, [RequisitionStatus::DRAFT, RequisitionStatus::REJECTED], true)) {
            $this->dispatch('notify', type: 'error', message: 'Only draft or rejected requisitions can be deleted.');

            return;
        }

        $req->delete();
        $this->dispatch('notify', type: 'success', message: 'Requisition deleted.');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $requisitions = Requisition::query()
            ->with(['vendor', 'account', 'items'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('requisition_number', 'like', '%' . $this->search . '%')
                    ->orWhere('title', 'like', '%' . $this->search . '%')
                    ->orWhere('requested_by', 'like', '%' . $this->search . '%');
            }))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('requested_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('requested_date', '<=', $this->dateTo))
            ->latest('created_at')
            ->paginate(config('accounting.per_page.requisitions', 15));

        $vendors  = Vendor::where('is_active', true)->orderBy('name')->get();
        $accounts = Account::where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        $viewing = $this->viewingId
            ? Requisition::with(['vendor', 'account', 'items'])->find($this->viewingId)
            : null;

        $layout = view()->exists('layouts.app') ? 'layouts.app' : 'components.layouts.app';

        return view('accounting::livewire.requisitions', [
            'requisitions' => $requisitions,
            'vendors'      => $vendors,
            'accounts'     => $accounts,
            'viewing'      => $viewing,
        ])->layout($layout, ['title' => __('Requisitions')]);
    }
}
