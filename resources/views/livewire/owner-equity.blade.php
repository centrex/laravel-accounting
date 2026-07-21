<div>
<x-tallui-notification />

<x-tallui-page-header title="Owner's Equity" subtitle="Capital contributions, drawings and retained earnings" icon="o-user-circle">
    <x-slot:actions>
        @can('accounting.equity.manage')
            <x-tallui-button wire:click="openDrawing" icon="o-arrow-up-tray" class="btn-ghost btn-sm">Record Drawing</x-tallui-button>
            <x-tallui-button wire:click="openContribution" icon="o-plus" class="btn-primary btn-sm">Record Contribution</x-tallui-button>
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

{{-- Balances --}}
<div class="stats shadow w-full mb-4 grid grid-cols-1 md:grid-cols-3">
    <x-tallui-stat title="Capital ({{ $capitalAccount?->code }})" value="{{ number_format($capitalAccount?->getCurrentBalance() ?? 0, 2) }}" icon="o-banknotes" icon-color="text-success" />
    <x-tallui-stat title="Owner Drawings ({{ $drawingsAccount?->code }})" value="{{ number_format($drawingsAccount?->getCurrentBalance() ?? 0, 2) }}" icon="o-arrow-up-tray" icon-color="text-warning" />
    <x-tallui-stat title="Retained Earnings ({{ $retainedEarningsAccount?->code }})" value="{{ number_format($retainedEarningsAccount?->getCurrentBalance() ?? 0, 2) }}" icon="o-chart-bar" icon-color="text-info" />
</div>

@unless($capitalAccount && $drawingsAccount)
    <x-tallui-alert type="warning" title="Chart of accounts incomplete" class="mb-4">
        The Capital ({{ config('accounting.accounts.capital', '3000') }}) or Owner Drawings ({{ config('accounting.accounts.owner_drawings', '3200') }}) account is missing.
        Run <code>Accounting::initializeChartOfAccounts()</code> to seed the standard chart of accounts.
    </x-tallui-alert>
@endunless

{{-- Activity --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Date</th>
                    <th>Reference</th>
                    <th>Account</th>
                    <th>Description</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right pr-5">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($entries as $line)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 text-sm text-base-content/70">{{ $line->journalEntry?->date?->format('M d, Y') }}</td>
                        <td class="text-sm font-mono text-primary">{{ $line->journalEntry?->reference }}</td>
                        <td class="text-sm">{{ $line->account?->code }} - {{ $line->account?->name }}</td>
                        <td class="text-sm text-base-content/60">{{ $line->journalEntry?->description }}</td>
                        <td class="text-right text-sm font-mono">{{ $line->type === 'debit' ? number_format($line->amount, 2) : '' }}</td>
                        <td class="text-right text-sm font-mono pr-5">{{ $line->type === 'credit' ? number_format($line->amount, 2) : '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-tallui-empty-state title="No equity activity yet" description="Record a capital contribution or owner drawing to get started" icon="o-user-circle" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $entries->links() }}</div>
</x-tallui-card>

{{-- Record Contribution Modal --}}
<x-tallui-modal id="equity-contribution-modal" title="Record Capital Contribution" icon="o-plus-circle" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showContributionModal) $dispatch('open-modal', 'equity-contribution-modal'); else $dispatch('close-modal', 'equity-contribution-modal')"
            @modal-closed.window="if ($event.detail === 'equity-contribution-modal') $wire.showContributionModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordContribution" class="space-y-4">
        <p class="text-sm text-base-content/60">
            Records: DR selected account / CR {{ $capitalAccount?->code }} {{ $capitalAccount?->name }}.
        </p>

        <x-tallui-form-group label="Amount *" :error="$errors->first('contribution_amount')">
            <x-tallui-input type="number" step="0.01" wire:model="contribution_amount" class="text-right" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('contribution_date')">
                <x-tallui-input type="date" wire:model="contribution_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Deposit Into *" :error="$errors->first('contribution_account_code')">
                <x-tallui-select wire:model="contribution_account_code">
                    @foreach($cashBankAccounts as $account)
                        <option value="{{ $account->code }}">{{ $account->code }} - {{ $account->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Description">
            <x-tallui-input wire:model="contribution_description" placeholder="e.g., Share capital – founding shareholders" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showContributionModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordContribution" spinner="save" class="btn-primary">Record Contribution</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Record Drawing Modal --}}
<x-tallui-modal id="equity-drawing-modal" title="Record Owner Drawing" icon="o-arrow-up-tray" size="md">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showDrawingModal) $dispatch('open-modal', 'equity-drawing-modal'); else $dispatch('close-modal', 'equity-drawing-modal')"
            @modal-closed.window="if ($event.detail === 'equity-drawing-modal') $wire.showDrawingModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordDrawing" class="space-y-4">
        <p class="text-sm text-base-content/60">
            Records: DR {{ $drawingsAccount?->code }} {{ $drawingsAccount?->name }} / CR selected account.
        </p>

        <x-tallui-form-group label="Amount *" :error="$errors->first('drawing_amount')">
            <x-tallui-input type="number" step="0.01" wire:model="drawing_amount" class="text-right" />
        </x-tallui-form-group>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Date *" :error="$errors->first('drawing_date')">
                <x-tallui-input type="date" wire:model="drawing_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Paid From *" :error="$errors->first('drawing_account_code')">
                <x-tallui-select wire:model="drawing_account_code">
                    @foreach($cashBankAccounts as $account)
                        <option value="{{ $account->code }}">{{ $account->code }} - {{ $account->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        <x-tallui-form-group label="Description">
            <x-tallui-input wire:model="drawing_description" placeholder="Optional" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showDrawingModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordDrawing" spinner="save" class="btn-primary">Record Drawing</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>
