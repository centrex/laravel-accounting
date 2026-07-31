<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\LoanFacilities;
use Centrex\Accounting\Models\{Account, LoanFacility};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class LoanFacilityEditLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_editing_a_facility_with_no_activity_updates_every_field_including_financials(): void
    {
        $facility = $this->createFacility(lenderName: 'Old Lender', amount: 100000, currency: 'BDT', rate: 0.02);

        Livewire::test(LoanFacilities::class)
            ->call('openEdit', $facility->id)
            ->assertSet('editFinancialsLocked', false)
            ->set('edit_lender_name', 'New Lender Name')
            ->set('edit_monthly_rate_pct', '2.5')
            ->set('edit_loan_amount', '250000')
            ->set('edit_currency', 'usd')
            ->set('edit_exchange_rate', '115')
            ->set('edit_contact', 'someone@lender.com')
            ->call('submitEdit');

        $fresh = $facility->fresh();
        $this->assertEquals('New Lender Name', $fresh->lender_name);
        $this->assertEquals(0.025, (float) $fresh->monthly_rate);
        $this->assertEquals('250000.00', $fresh->loan_amount);
        $this->assertEquals('USD', $fresh->currency);
        $this->assertEquals(115.0, (float) $fresh->exchange_rate);
        $this->assertEquals('someone@lender.com', $fresh->lender_contact);
    }

    public function test_editing_a_facility_with_a_posted_drawdown_locks_financial_fields(): void
    {
        $facility = $this->createFacility(lenderName: 'Active Lender', amount: 100000, currency: 'BDT', rate: 0.02);
        $this->accounting->drawdownLoan($facility, 50000, now()->toDateString(), 'DD-001')->post();

        $originalCurrency = $facility->currency;
        $originalAmount = $facility->loan_amount;
        $originalRate = (float) $facility->exchange_rate;

        Livewire::test(LoanFacilities::class)
            ->call('openEdit', $facility->id)
            ->assertSet('editFinancialsLocked', true)
            ->set('edit_currency', 'usd') // attempted tamper, should be ignored
            ->set('edit_loan_amount', '999999')
            ->set('edit_exchange_rate', '1')
            ->set('edit_lender_name', 'Renamed After Drawdown')
            ->set('edit_monthly_rate_pct', '3') // still allowed — future accruals only
            ->call('submitEdit');

        $fresh = $facility->fresh();
        $this->assertEquals($originalCurrency, $fresh->currency, 'currency must not change once a drawdown has posted');
        $this->assertEquals($originalAmount, $fresh->loan_amount, 'loan_amount must not change once a drawdown has posted');
        $this->assertEquals($originalRate, (float) $fresh->exchange_rate, 'exchange_rate must not change once a drawdown has posted');
        $this->assertEquals('Renamed After Drawdown', $fresh->lender_name);
        $this->assertEquals(0.03, (float) $fresh->monthly_rate, 'monthly_rate stays editable — only affects future accruals');
    }

    public function test_loan_term_is_never_exposed_as_an_editable_field(): void
    {
        $facility = $this->createFacility(lenderName: 'Term Lender', amount: 10000, currency: 'BDT', rate: 0.02);

        $component = Livewire::test(LoanFacilities::class)
            ->call('openEdit', $facility->id);

        $component->assertSet('editFacilityTerm', 'short_term');

        // submitEdit() never touches loan_term regardless of what's set — there's no
        // edit_loan_term property at all for it to come from.
        $component->set('edit_lender_name', 'Still Short Term')->call('submitEdit');

        $this->assertEquals('short_term', $facility->fresh()->loan_term);
    }

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1100', 'name' => 'Bank',                              'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2400', 'name' => 'Short-term Loans Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2420', 'name' => 'Accrued Interest — Short-term',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Loans Payable',          'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '2520', 'name' => 'Accrued Interest — Long-term',     'type' => 'liability', 'subtype' => 'long_term_liability'],
        ];

        foreach ($accounts as $data) {
            Account::create($data);
        }
    }

    private function createFacility(string $lenderName, float $amount, string $currency, float $rate): LoanFacility
    {
        return $this->accounting->addLoanFacility(
            lenderName: $lenderName,
            loanType: 'term_loan',
            loanTerm: 'short_term',
            monthlyRate: $rate,
            loanAmount: $amount,
            currency: $currency,
            exchangeRate: 1.0,
        );
    }
}
