<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\{Account, JournalEntryLine, LoanFacility};
use Livewire\Attributes\Computed;
use Livewire\{Component, WithPagination};

class LoanFacilities extends Component
{
    use WithCurrency;
    use WithPagination;

    public string $search = '';

    public string $termFilter = '';

    public string $typeFilter = '';

    // Create facility form
    public bool $showCreateModal = false;

    public string $lender_name = '';

    public string $loan_type = 'term_loan';

    public string $loan_term = 'short_term';

    public string $monthly_rate_pct = '';

    public string $sbu_code = '';

    public string $loan_amount = '';

    public string $currency = '';

    public string $exchange_rate = '';

    public string $disbursed_at = '';

    public string $due_at = '';

    public string $tenure_months = '';

    public string $contact = '';

    // Action (drawdown / pay interest / repay) form
    public bool $showActionModal = false;

    public ?int $actionFacilityId = null;

    public string $actionFacilityCurrency = '';

    public string $actionType = '';

    public string $action_amount = '';

    public string $action_date = '';

    public string $action_reference = '';

    public string $action_description = '';

    public string $action_account_code = '';

    // Edit form
    public bool $showEditModal = false;

    public ?int $editFacilityId = null;

    public bool $editFinancialsLocked = false;

    public string $editFacilityTerm = '';

    public string $edit_lender_name = '';

    public string $edit_loan_type = '';

    public string $edit_monthly_rate_pct = '';

    public string $edit_sbu_code = '';

    public string $edit_loan_amount = '';

    public string $edit_currency = '';

    public string $edit_exchange_rate = '';

    public string $edit_due_at = '';

    public string $edit_tenure_months = '';

    public string $edit_contact = '';

    protected array $queryString = ['search', 'termFilter', 'typeFilter'];

    public function openCreate(): void
    {
        $this->reset([
            'lender_name', 'loan_type', 'loan_term', 'monthly_rate_pct', 'sbu_code',
            'loan_amount', 'currency', 'exchange_rate', 'disbursed_at', 'due_at', 'tenure_months', 'contact',
        ]);
        $this->loan_type = 'term_loan';
        $this->loan_term = 'short_term';
        $this->currency = config('accounting.base_currency', 'BDT');
        $this->exchange_rate = '1';
        $this->disbursed_at = now()->format('Y-m-d');
        $this->showCreateModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'lender_name'      => 'required|string|max:255',
            'loan_type'        => 'required|in:term_loan,working_capital,inter_company,director,equipment,overdraft,bridge',
            'loan_term'        => 'required|in:short_term,long_term',
            'monthly_rate_pct' => 'required|numeric|min:0|max:100',
            'sbu_code'         => 'nullable|string|max:32',
            'loan_amount'      => 'nullable|numeric|min:0',
            'currency'         => 'nullable|string|size:3',
            'exchange_rate'    => 'nullable|numeric|min:0.000001',
            'disbursed_at'     => 'nullable|date',
            'due_at'           => 'nullable|date',
            'tenure_months'    => 'nullable|integer|min:1',
            'contact'          => 'nullable|string|max:255',
        ]);

        try {
            app(Accounting::class)->addLoanFacility(
                lenderName: $this->lender_name,
                loanType: $this->loan_type,
                loanTerm: $this->loan_term,
                monthlyRate: ((float) $this->monthly_rate_pct) / 100,
                sbuCode: $this->sbu_code ?: null,
                loanAmount: $this->loan_amount !== '' ? (float) $this->loan_amount : null,
                disbursedAt: $this->disbursed_at ?: null,
                dueAt: $this->due_at ?: null,
                tenureMonths: $this->tenure_months !== '' ? (int) $this->tenure_months : null,
                contact: $this->contact ?: null,
                currency: $this->currency ?: null,
                exchangeRate: $this->exchange_rate !== '' ? (float) $this->exchange_rate : null,
            );

            $this->dispatch('notify', type: 'success', message: 'Loan facility added.');
            $this->showCreateModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    #[Computed]
    public function fundAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::where('is_active', true)
            ->where('type', 'asset')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', 'like', '11%'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function openAction(int $id, string $type): void
    {
        $this->actionFacilityId = $id;
        $this->actionFacilityCurrency = LoanFacility::findOrFail($id)->currency;
        $this->actionType = $type;
        $this->action_amount = '';
        $this->action_date = now()->format('Y-m-d');
        $this->action_reference = strtoupper($type) . '-' . now()->format('YmdHis');
        $this->action_description = '';
        $this->action_account_code = config('accounting.accounts.bank', '1100');
        $this->showActionModal = true;
    }

    public function submitAction(): void
    {
        $this->validate([
            'action_amount'       => 'required|numeric|min:0.01',
            'action_date'         => 'required|date',
            'action_reference'    => 'required|string|max:255',
            'action_account_code' => 'required|string',
        ]);

        $facility = LoanFacility::findOrFail($this->actionFacilityId);
        $accounting = app(Accounting::class);

        try {
            $entry = match ($this->actionType) {
                'drawdown' => $accounting->drawdownLoan(
                    $facility,
                    (float) $this->action_amount,
                    $this->action_date,
                    $this->action_reference,
                    $this->action_description ?: null,
                    accountCode: $this->action_account_code,
                ),
                'pay_interest' => $accounting->payLoanInterest(
                    $facility,
                    (float) $this->action_amount,
                    $this->action_date,
                    $this->action_reference,
                    accountCode: $this->action_account_code,
                ),
                'repay' => $accounting->repayLoan(
                    $facility,
                    (float) $this->action_amount,
                    $this->action_date,
                    $this->action_reference,
                    $this->action_description ?: null,
                    accountCode: $this->action_account_code,
                ),
                default => throw new \RuntimeException('Unknown action.'),
            };
            $entry->post();

            $this->dispatch('notify', type: 'success', message: 'Recorded successfully.');
            $this->showActionModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function accrueInterest(int $id): void
    {
        $facility = LoanFacility::findOrFail($id);

        try {
            $entry = app(Accounting::class)->accrueLoanInterest($facility);
            $entry?->post();

            $this->dispatch('notify', type: $entry ? 'success' : 'info', message: $entry
                ? 'Interest accrued for ' . $facility->lender_name . '.'
                : 'No outstanding principal — nothing to accrue.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function toggleActive(int $id): void
    {
        $facility = LoanFacility::findOrFail($id);
        $facility->update(['is_active' => !$facility->is_active]);

        $this->dispatch('notify', type: 'success', message: $facility->is_active ? 'Facility reactivated.' : 'Facility marked inactive.');
    }

    public function openEdit(int $id): void
    {
        $this->resetValidation();
        $facility = LoanFacility::findOrFail($id);

        $this->editFacilityId = $facility->id;
        $this->editFinancialsLocked = $this->hasPostedActivity($facility);
        $this->editFacilityTerm = $facility->loan_term;
        $this->edit_lender_name = $facility->lender_name;
        $this->edit_loan_type = $facility->loan_type;
        $this->edit_monthly_rate_pct = (string) round(((float) $facility->monthly_rate) * 100, 4);
        $this->edit_sbu_code = $facility->sbu_code ?? '';
        $this->edit_loan_amount = $facility->loan_amount !== null ? (string) $facility->loan_amount : '';
        $this->edit_currency = $facility->currency;
        $this->edit_exchange_rate = (string) $facility->exchange_rate;
        $this->edit_due_at = $facility->due_at?->format('Y-m-d') ?? '';
        $this->edit_tenure_months = $facility->tenure_months !== null ? (string) $facility->tenure_months : '';
        $this->edit_contact = $facility->lender_contact ?? '';
        $this->showEditModal = true;
    }

    public function submitEdit(): void
    {
        $facility = LoanFacility::findOrFail($this->editFacilityId);
        $locked = $this->hasPostedActivity($facility);

        $rules = [
            'edit_lender_name'      => 'required|string|max:255',
            'edit_loan_type'        => 'required|in:term_loan,working_capital,inter_company,director,equipment,overdraft,bridge',
            'edit_monthly_rate_pct' => 'required|numeric|min:0|max:100',
            'edit_sbu_code'         => 'nullable|string|max:32',
            'edit_due_at'           => 'nullable|date',
            'edit_tenure_months'    => 'nullable|integer|min:1',
            'edit_contact'          => 'nullable|string|max:255',
        ];

        if (!$locked) {
            $rules['edit_loan_amount'] = 'nullable|numeric|min:0';
            $rules['edit_currency'] = 'required|string|size:3';
            $rules['edit_exchange_rate'] = 'required|numeric|min:0.000001';
        }

        $this->validate($rules);

        $data = [
            'lender_name'    => $this->edit_lender_name,
            'loan_type'      => $this->edit_loan_type,
            'monthly_rate'   => ((float) $this->edit_monthly_rate_pct) / 100,
            'sbu_code'       => $this->edit_sbu_code ? strtoupper(trim($this->edit_sbu_code)) : null,
            'due_at'         => $this->edit_due_at ?: null,
            'tenure_months'  => $this->edit_tenure_months !== '' ? (int) $this->edit_tenure_months : null,
            'lender_contact' => $this->edit_contact ?: null,
        ];

        // loan_amount/currency/exchange_rate are only safe to edit before any drawdown/interest/
        // repayment has posted — those entries already convert amounts to base currency using
        // the exchange_rate in effect at the time, so changing it afterward would desync every
        // *Local() figure from the GL history it's supposed to describe. loan_term is never
        // editable at all (not even here) — it picked which GL account range (240x vs 250x) got
        // provisioned at addLoanFacility() time; relabeling it after the fact wouldn't move the
        // account, so the record would contradict its own principal_account_id.
        if (!$locked) {
            $data['loan_amount'] = $this->edit_loan_amount !== '' ? (float) $this->edit_loan_amount : null;
            $data['currency'] = strtoupper($this->edit_currency);
            $data['exchange_rate'] = (float) $this->edit_exchange_rate;
        }

        $facility->update($data);

        $this->dispatch('notify', type: 'success', message: 'Loan facility updated.');
        $this->showEditModal = false;
    }

    /**
     * True once this facility's dedicated GL accounts (principal/interest) have any posted
     * activity — at that point loan_amount/currency/exchange_rate are locked to keep the
     * record consistent with what's already posted.
     */
    private function hasPostedActivity(LoanFacility $facility): bool
    {
        return JournalEntryLine::query()
            ->whereIn('account_id', array_filter([$facility->principal_account_id, $facility->interest_account_id]))
            ->whereHas('journalEntry', fn ($query) => $query->where('status', 'posted'))
            ->exists();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $facilities = LoanFacility::query()
            ->with(['principalAccount', 'interestAccount'])
            ->when($this->search, fn ($q) => $q->where('lender_name', 'like', '%' . $this->search . '%'))
            ->when($this->termFilter, fn ($q) => $q->where('loan_term', $this->termFilter))
            ->when($this->typeFilter, fn ($q) => $q->where('loan_type', $this->typeFilter))
            ->orderBy('loan_term')
            ->orderBy('lender_name')
            ->paginate(config('accounting.per_page.loans', 15));

        $layout = view()->exists('layouts.app')
        ? 'layouts.app'
        : 'components.layouts.app';

        return view('accounting::livewire.loan-facilities', [
            'facilities' => $facilities,
        ])->layout($layout, ['title' => __('Loan Facilities')]);
    }
}
