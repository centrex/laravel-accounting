<div>
<x-tallui-notification />

<x-tallui-page-header title="Invoices" subtitle="Manage customer invoices and payments" icon="o-document-text">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Invoice</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Invoices Table --}}
<livewire:accounting-invoice-table />

{{-- Create Invoice Modal --}}
<x-tallui-modal id="invoice-modal" title="New Invoice" icon="o-document-text" size="xl">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'invoice-modal'); else $dispatch('close-modal', 'invoice-modal')"
            @modal-closed.window="if ($event.detail === 'invoice-modal') $wire.showModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Customer *" :error="$errors->first('customer_id')">
                <x-tallui-select wire:model="customer_id" class="{{ $errors->has('customer_id') ? 'select-error' : '' }}">
                    <option value="">Select Customer</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->organization_name ?: $customer->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Currency">
                <x-tallui-input wire:model="currency" maxlength="3" class="input-sm" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Invoice Date *" :error="$errors->first('invoice_date')">
                <x-tallui-input type="date" wire:model.live="invoice_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Due Date *" :error="$errors->first('due_date')">
                <x-tallui-input type="date" wire:model="due_date" />
            </x-tallui-form-group>
        </div>

        {{-- Line Items --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-semibold text-base-content/70">Line Items</label>
                <x-tallui-button wire:click="addItem" icon="o-plus" class="btn-ghost btn-xs">Add Item</x-tallui-button>
            </div>
            <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                @foreach($items as $i => $item)
                    <div class="flex gap-2 items-start bg-base-200 border border-base-200 p-2 rounded-xl">
                        <div class="flex-1">
                            <x-tallui-input wire:model="items.{{ $i }}.description" placeholder="Description" class="input-sm" />
                            @error("items.{$i}.description") <p class="text-error text-xs">{{ $message }}</p> @enderror
                        </div>
                        <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.quantity" placeholder="Qty" class="input input-sm w-20 border-base-300 text-right" />
                        <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.unit_price" placeholder="Price" class="input input-sm w-28 border-base-300 text-right" />
                        <select wire:model.live="items.{{ $i }}.tax_rate_id" class="select select-sm w-28 border-base-300">
                            <option value="">Other %</option>
                            @foreach($this->activeTaxRates as $taxRate)
                                <option value="{{ $taxRate->id }}">{{ $taxRate->name }} ({{ $taxRate->rate }}%)</option>
                            @endforeach
                        </select>
                        @if(empty($item['tax_rate_id']))
                            <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.tax_rate" placeholder="Tax%" class="input input-sm w-20 border-base-300 text-right" />
                        @endif
                        <x-tallui-button wire:click="removeItem({{ $i }})" icon="o-trash" class="btn-ghost btn-sm text-error" />
                    </div>
                @endforeach
            </div>

            {{-- Totals --}}
            <div class="mt-3 p-3 bg-base-200 rounded-xl border border-base-200 text-sm">
                <div class="flex justify-between mb-1">
                    <span class="text-base-content/60">Subtotal</span>
                    <span class="font-mono">{{ number_format($this->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between mb-1">
                    <span class="text-base-content/60">Tax</span>
                    <span class="font-mono">{{ number_format($this->taxTotal, 2) }}</span>
                </div>
                <div class="flex justify-between font-bold border-t border-base-200 pt-1 mt-1">
                    <span>Total</span>
                    <span class="font-mono">{{ number_format($this->total, 2) }}</span>
                </div>
            </div>
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" placeholder="Optional notes…" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Create Invoice</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Record Payment Modal --}}
<x-tallui-modal id="pay-invoice-modal" title="Record Payment" icon="o-banknotes" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showPayModal) $dispatch('open-modal', 'pay-invoice-modal'); else $dispatch('close-modal', 'pay-invoice-modal')"
            @modal-closed.window="if ($event.detail === 'pay-invoice-modal') $wire.showPayModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordPayment" class="space-y-4">
        @if($this->payingInvoice)
            <div class="flex items-center justify-between rounded-xl border border-base-300 bg-base-200/40 px-4 py-3">
                <div>
                    <div class="text-xs text-base-content/50">Invoice</div>
                    <div class="font-semibold text-base-content">{{ $this->payingInvoice->invoice_number }}</div>
                    <div class="font-light text-base-content">{{ $this->payingInvoice->customer->organization_name ?? $this->payingInvoice->customer->name }}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-base-content/50">Amount Due</div>
                    <div class="font-semibold text-base-content">{{ $this->payingInvoice->currency }} {{ number_format($this->payingInvoice->balance, 2) }}</div>
                </div>
            </div>
        @endif
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Payment Date *" :error="$errors->first('pay_date')">
                <x-tallui-input type="date" wire:model="pay_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Amount *" :error="$errors->first('pay_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="pay_amount" class="text-right" />
            </x-tallui-form-group>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Payment Method *" :error="$errors->first('pay_method')">
                <x-tallui-select wire:model="pay_method">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="card">Card</option>
                    <option value="mobile_banking">Mobile Banking</option>
                    <option value="other">Other</option>
                </x-tallui-select>
            </x-tallui-form-group>
            <x-tallui-form-group label="Bank / Cash Account *" :error="$errors->first('pay_account_code')">
                <x-tallui-select wire:model="pay_account_code">
                    <option value="">— Select Account —</option>
                    @foreach($this->paymentAccounts as $acct)
                        <option value="{{ $acct->code }}">{{ $acct->code }} — {{ $acct->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Shipping / Charge (deducted from AR)" :error="$errors->first('pay_charge_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="pay_charge_amount" class="text-right" placeholder="0.00" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Charge Account" :error="$errors->first('pay_charge_account_code')">
                <x-tallui-select wire:model="pay_charge_account_code">
                    <option value="">— Select Account —</option>
                    @foreach($this->chargeAccounts as $acct)
                        <option value="{{ $acct->code }}">{{ $acct->code }} — {{ $acct->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <x-tallui-form-group label="Reference">
            <x-tallui-input wire:model="pay_reference" placeholder="Transaction ID, check #…" />
        </x-tallui-form-group>
        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="pay_notes" rows="2" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showPayModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button
            wire:click="recordPayment"
            wire:confirm="Record this payment for invoice {{ $this->payingInvoice?->invoice_number }}? This will post a journal entry and cannot be undone."
            spinner="recordPayment"
            class="btn-success"
        >Record Payment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
@include('accounting::livewire.partials.audit-trail-modal')
</div>
