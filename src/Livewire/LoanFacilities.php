<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Livewire;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Concerns\WithCurrency;
use Centrex\Accounting\Models\LoanFacility;
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

    public string $disbursed_at = '';

    public string $due_at = '';

    public string $tenure_months = '';

    public string $contact = '';

    // Action (drawdown / pay interest / repay) form
    public bool $showActionModal = false;

    public ?int $actionFacilityId = null;

    public string $actionType = '';

    public string $action_amount = '';

    public string $action_date = '';

    public string $action_reference = '';

    public string $action_description = '';

    protected array $queryString = ['search', 'termFilter', 'typeFilter'];

    public function openCreate(): void
    {
        $this->reset([
            'lender_name', 'loan_type', 'loan_term', 'monthly_rate_pct', 'sbu_code',
            'loan_amount', 'disbursed_at', 'due_at', 'tenure_months', 'contact',
        ]);
        $this->loan_type = 'term_loan';
        $this->loan_term = 'short_term';
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
            );

            $this->dispatch('notify', type: 'success', message: 'Loan facility added.');
            $this->showCreateModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openAction(int $id, string $type): void
    {
        $this->actionFacilityId = $id;
        $this->actionType = $type;
        $this->action_amount = '';
        $this->action_date = now()->format('Y-m-d');
        $this->action_reference = strtoupper($type) . '-' . now()->format('YmdHis');
        $this->action_description = '';
        $this->showActionModal = true;
    }

    public function submitAction(): void
    {
        $this->validate([
            'action_amount'    => 'required|numeric|min:0.01',
            'action_date'      => 'required|date',
            'action_reference' => 'required|string|max:255',
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
                ),
                'pay_interest' => $accounting->payLoanInterest(
                    $facility,
                    (float) $this->action_amount,
                    $this->action_date,
                    $this->action_reference,
                ),
                'repay' => $accounting->repayLoan(
                    $facility,
                    (float) $this->action_amount,
                    $this->action_date,
                    $this->action_reference,
                    $this->action_description ?: null,
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
