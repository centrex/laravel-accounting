<div>
<x-tallui-notification />

<x-tallui-page-header title="Requisitions" subtitle="Manage purchase and expense requisitions" icon="o-document-text">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Requisition</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Req # or title…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="Type">
                <x-tallui-select wire:model.live="typeFilter" class="select-sm">
                    <option value="">All Types</option>
                    <option value="purchase">Purchase</option>
                    <option value="expense">Expense</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="converted">Converted</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="From">
                <x-tallui-input type="date" wire:model.live="dateFrom" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="To">
                <x-tallui-input type="date" wire:model.live="dateTo" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Requisitions Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Req #</th>
                    <th>Type</th>
                    <th>Title</th>
                    <th>Vendor / Account</th>
                    <th>Requested By</th>
                    <th>Date</th>
                    <th class="text-right">Total</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($requisitions as $req)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $req->requisition_number }}</td>
                        <td>
                            <x-tallui-badge :type="$req->type->value === 'purchase' ? 'info' : 'secondary'" size="sm">
                                {{ $req->type->label() === 'Purchase Requisition' ? 'Purchase' : 'Expense' }}
                            </x-tallui-badge>
                        </td>
                        <td class="text-sm font-medium max-w-48 truncate">{{ $req->title }}</td>
                        <td class="text-sm text-base-content/60">
                            {{ $req->vendor?->name ?? $req->account?->name ?? '—' }}
                        </td>
                        <td class="text-sm text-base-content/60">{{ $req->requested_by ?? '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $req->requested_date->format('d M Y') }}</td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($req->total_amount, 2) }}</td>
                        <td>
                            <x-tallui-badge :type="match($req->status->value) {
                                'approved'  => 'success',
                                'submitted' => 'warning',
                                'rejected'  => 'error',
                                'converted' => 'info',
                                default     => 'neutral',
                            }">{{ $req->status->label() }}</x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button wire:click="openDetail({{ $req->id }})"
                                    class="btn-ghost btn-xs" icon="o-eye" />

                                @if($req->status->value === 'draft')
                                    <x-tallui-button wire:click="submitRequisition({{ $req->id }})"
                                        wire:confirm="Submit {{ $req->requisition_number }} for approval?"
                                        class="btn-info btn-xs">Submit</x-tallui-button>
                                    <x-tallui-button wire:click="deleteRequisition({{ $req->id }})"
                                        wire:confirm="Delete this requisition?"
                                        class="btn-error btn-xs" icon="o-trash" />
                                @endif

                                @if($req->status->value === 'submitted')
                                    <x-tallui-button wire:click="approveRequisition({{ $req->id }})"
                                        wire:confirm="Approve {{ $req->requisition_number }}?"
                                        class="btn-success btn-xs">Approve</x-tallui-button>
                                    <x-tallui-button wire:click="openReject({{ $req->id }})"
                                        class="btn-error btn-xs">Reject</x-tallui-button>
                                @endif

                                @if($req->status->value === 'approved')
                                    <x-tallui-button wire:click="convertRequisition({{ $req->id }})"
                                        wire:confirm="Convert to {{ $req->type->value === 'purchase' ? 'Bill' : 'Expense' }}?"
                                        class="btn-primary btn-xs">Convert</x-tallui-button>
                                @endif

                                @if($req->status->value === 'rejected')
                                    <x-tallui-button wire:click="deleteRequisition({{ $req->id }})"
                                        wire:confirm="Delete this rejected requisition?"
                                        class="btn-error btn-xs" icon="o-trash" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-12">
                            <x-tallui-empty-state title="No requisitions found"
                                description="Create a purchase or expense requisition to get started."
                                icon="o-document-text" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($requisitions->hasPages())
        <div class="p-4 border-t border-base-200">{{ $requisitions->links() }}</div>
    @endif
</x-tallui-card>

{{-- Create Modal --}}
<x-tallui-modal wire:model="showModal" title="New Requisition" size="xl">
    <form wire:submit="save" class="space-y-4">

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Type" :required="true">
                <x-tallui-select wire:model.live="type" class="select-sm">
                    <option value="purchase">Purchase Requisition</option>
                    <option value="expense">Expense Requisition</option>
                </x-tallui-select>
                <x-tallui-error-message field="type" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Title" :required="true">
                <x-tallui-input wire:model="title" placeholder="Brief title…" class="input-sm" />
                <x-tallui-error-message field="title" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <x-tallui-form-group label="Requested By">
                <x-tallui-input wire:model="requested_by" placeholder="Name…" class="input-sm" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Request Date" :required="true">
                <x-tallui-input type="date" wire:model="requested_date" class="input-sm" />
                <x-tallui-error-message field="requested_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Required By">
                <x-tallui-input type="date" wire:model="required_date" class="input-sm" />
                <x-tallui-error-message field="required_date" />
            </x-tallui-form-group>
        </div>

        @if($type === 'purchase')
            <x-tallui-form-group label="Vendor">
                <x-tallui-select wire:model="vendor_id" class="select-sm">
                    <option value="">— Select vendor —</option>
                    @foreach($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        @else
            <x-tallui-form-group label="Expense Account">
                <x-tallui-select wire:model="account_id" class="select-sm">
                    <option value="">— Select account —</option>
                    @foreach($accounts as $acct)
                        <option value="{{ $acct->id }}">{{ $acct->code }} — {{ $acct->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        @endif

        <x-tallui-form-group label="Description">
            <x-tallui-form-textarea wire:model="description" placeholder="Purpose or justification…" :rows="2" />
        </x-tallui-form-group>

        {{-- Line Items --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold">Items</span>
                <x-tallui-button wire:click="addItem" icon="o-plus" class="btn-ghost btn-xs">Add line</x-tallui-button>
            </div>
            <div class="overflow-x-auto border border-base-200 rounded-lg">
                <table class="table table-xs w-full">
                    <thead>
                        <tr class="bg-base-100 text-xs uppercase text-base-content/50">
                            <th class="w-full pl-3">Description</th>
                            <th class="w-24 text-right">Qty</th>
                            <th class="w-32 text-right">Unit Price</th>
                            <th class="w-32 text-right">Total</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $i => $item)
                            <tr>
                                <td class="pl-3">
                                    <x-tallui-input wire:model="items.{{ $i }}.description"
                                        placeholder="Item description…" class="input-xs w-full" />
                                    <x-tallui-error-message field="items.{{ $i }}.description" />
                                </td>
                                <td>
                                    <x-tallui-input type="number" wire:model.live="items.{{ $i }}.quantity"
                                        min="0.0001" step="0.001" class="input-xs w-20 text-right" />
                                </td>
                                <td>
                                    <x-tallui-input type="number" wire:model.live="items.{{ $i }}.unit_price"
                                        min="0" step="0.01" class="input-xs w-28 text-right" />
                                </td>
                                <td class="text-right pr-2 font-mono text-sm">
                                    {{ number_format((float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0), 2) }}
                                </td>
                                <td class="text-center">
                                    @if(count($items) > 1)
                                        <button type="button" wire:click="removeItem({{ $i }})"
                                            class="btn btn-ghost btn-xs text-error">✕</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-base-100 font-semibold">
                            <td colspan="3" class="text-right pr-3 py-2 text-sm">Total</td>
                            <td class="text-right pr-2 font-mono text-sm">{{ number_format($this->subtotal, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <x-tallui-error-message field="items" />
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-form-textarea wire:model="notes" placeholder="Internal notes…" :rows="2" />
        </x-tallui-form-group>

        <div class="flex justify-end gap-2 pt-2">
            <x-tallui-button type="button" wire:click="$set('showModal', false)" class="btn-ghost btn-sm">Cancel</x-tallui-button>
            <x-tallui-button type="submit" class="btn-primary btn-sm" :spinner="'save'">Create Requisition</x-tallui-button>
        </div>
    </form>
</x-tallui-modal>

{{-- Reject Modal --}}
<x-tallui-modal wire:model="showRejectModal" title="Reject Requisition" size="md">
    <form wire:submit="confirmReject" class="space-y-4">
        <x-tallui-form-group label="Reason for rejection" :required="true">
            <x-tallui-form-textarea wire:model="rejectionReason" placeholder="Provide a reason…" :rows="3" />
            <x-tallui-error-message field="rejectionReason" />
        </x-tallui-form-group>
        <div class="flex justify-end gap-2">
            <x-tallui-button type="button" wire:click="$set('showRejectModal', false)" class="btn-ghost btn-sm">Cancel</x-tallui-button>
            <x-tallui-button type="submit" class="btn-error btn-sm">Reject</x-tallui-button>
        </div>
    </form>
</x-tallui-modal>

{{-- Detail Modal --}}
@if($viewing)
<x-tallui-modal wire:model="showDetailModal" title="Requisition {{ $viewing->requisition_number }}" size="xl">
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div><span class="text-base-content/50">Type</span><p class="font-medium">{{ $viewing->type->label() }}</p></div>
            <div><span class="text-base-content/50">Status</span>
                <p><x-tallui-badge :type="match($viewing->status->value) {
                    'approved'  => 'success', 'submitted' => 'warning', 'rejected' => 'error',
                    'converted' => 'info', default => 'neutral',
                }">{{ $viewing->status->label() }}</x-tallui-badge></p>
            </div>
            <div class="col-span-2"><span class="text-base-content/50">Title</span><p class="font-semibold">{{ $viewing->title }}</p></div>
            @if($viewing->description)
                <div class="col-span-2"><span class="text-base-content/50">Description</span><p>{{ $viewing->description }}</p></div>
            @endif
            <div><span class="text-base-content/50">Requested By</span><p>{{ $viewing->requested_by ?? '—' }}</p></div>
            <div><span class="text-base-content/50">Request Date</span><p>{{ $viewing->requested_date->format('d M Y') }}</p></div>
            @if($viewing->required_date)
                <div><span class="text-base-content/50">Required By</span><p>{{ $viewing->required_date->format('d M Y') }}</p></div>
            @endif
            @if($viewing->vendor)
                <div><span class="text-base-content/50">Vendor</span><p>{{ $viewing->vendor->name }}</p></div>
            @endif
            @if($viewing->account)
                <div><span class="text-base-content/50">Account</span><p>{{ $viewing->account->code }} — {{ $viewing->account->name }}</p></div>
            @endif
            @if($viewing->rejection_reason)
                <div class="col-span-2">
                    <span class="text-base-content/50">Rejection Reason</span>
                    <p class="text-error">{{ $viewing->rejection_reason }}</p>
                </div>
            @endif
        </div>

        <div>
            <p class="text-xs uppercase font-semibold text-base-content/50 mb-2">Line Items</p>
            <div class="overflow-x-auto border border-base-200 rounded-lg">
                <table class="table table-xs w-full">
                    <thead>
                        <tr class="bg-base-100 text-xs uppercase text-base-content/50">
                            <th class="pl-3">Description</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right pr-3">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($viewing->items as $item)
                            <tr>
                                <td class="pl-3">{{ $item->description }}</td>
                                <td class="text-right font-mono">{{ number_format($item->quantity, 2) }}</td>
                                <td class="text-right font-mono">{{ number_format($item->unit_price, 2) }}</td>
                                <td class="text-right font-mono pr-3">{{ number_format($item->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-base-100 font-semibold">
                            <td colspan="3" class="text-right pr-3 py-2 text-sm">Total</td>
                            <td class="text-right font-mono pr-3">{{ number_format($viewing->total_amount, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @if($viewing->converted_to_id)
            <x-tallui-alert type="info">
                Converted to {{ class_basename($viewing->converted_to_type) }} #{{ $viewing->converted_to_id }}.
            </x-tallui-alert>
        @endif
    </div>
</x-tallui-modal>
@endif

</div>
