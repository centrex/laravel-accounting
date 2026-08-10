<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\LoanFacilities;
use Centrex\Accounting\Models\{Account, JournalEntryLine, LoanFacility};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class LoanFacilityActionFundSourceTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_opening_the_action_modal_defaults_the_fund_source_to_the_configured_bank_account(): void
    {
        $facility = $this->createFacility();

        Livewire::test(LoanFacilities::class)
            ->call('openAction', $facility->id, 'repay')
            ->assertSet('action_account_code', config('accounting.accounts.bank', '1100'));
    }

    public function test_the_fund_source_list_only_offers_active_10xx_11xx_asset_accounts(): void
    {
        $facility = $this->createFacility();

        $component = Livewire::test(LoanFacilities::class)
            ->call('openAction', $facility->id, 'repay');

        $codes = $component->get('fundAccounts')->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['1000', '1100'], $codes);
    }

    public function test_repaying_a_loan_from_a_selected_fund_source_credits_that_account_instead_of_the_default_bank(): void
    {
        $facility = $this->createFacility(amount: 100000);
        $this->accounting->drawdownLoan($facility, 100000, now()->toDateString(), 'DD-001')->post();

        Livewire::test(LoanFacilities::class)
            ->call('openAction', $facility->id, 'repay')
            ->set('action_amount', '20000')
            ->set('action_date', now()->toDateString())
            ->set('action_reference', 'RP-001')
            ->set('action_account_code', '1000')
            ->call('submitAction')
            ->assertHasNoErrors();

        $cash = Account::where('code', '1000')->firstOrFail();
        $bank = Account::where('code', '1100')->firstOrFail();

        $this->assertTrue(
            JournalEntryLine::where('account_id', $cash->id)->where('type', 'credit')->where('amount', 20000)->exists(),
            'repayment should credit the selected fund source (Cash)',
        );
        $this->assertFalse(
            JournalEntryLine::where('account_id', $bank->id)->where('type', 'credit')->exists(),
            'repayment should not touch the default Bank account when a different fund source was selected',
        );
    }

    public function test_submitting_the_action_without_a_fund_source_fails_validation(): void
    {
        $facility = $this->createFacility();

        Livewire::test(LoanFacilities::class)
            ->call('openAction', $facility->id, 'repay')
            ->set('action_amount', '1000')
            ->set('action_date', now()->toDateString())
            ->set('action_reference', 'RP-002')
            ->set('action_account_code', '')
            ->call('submitAction')
            ->assertHasErrors(['action_account_code']);
    }

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                              'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1100', 'name' => 'Bank',                              'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2400', 'name' => 'Short-term Loans Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2420', 'name' => 'Accrued Interest — Short-term',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Loans Payable',           'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '2520', 'name' => 'Accrued Interest — Long-term',      'type' => 'liability', 'subtype' => 'long_term_liability'],
        ];

        foreach ($accounts as $data) {
            Account::create($data);
        }
    }

    private function createFacility(float $amount = 100000): LoanFacility
    {
        return $this->accounting->addLoanFacility(
            lenderName: 'Test Lender',
            loanType: 'term_loan',
            loanTerm: 'short_term',
            monthlyRate: 0.02,
            loanAmount: $amount,
            currency: 'BDT',
            exchangeRate: 1.0,
        );
    }
}
