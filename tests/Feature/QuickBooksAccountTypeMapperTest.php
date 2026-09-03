<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Enums\AccountSubtype;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\QuickBooks\QuickBooksAccountTypeMapper;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QuickBooksAccountTypeMapperTest extends TestCase
{
    use RefreshDatabase;

    private QuickBooksAccountTypeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new QuickBooksAccountTypeMapper();
    }

    public function test_maps_cash_account_to_qbo_bank_type(): void
    {
        $account = Account::factory()->create(['type' => 'asset', 'subtype' => AccountSubtype::CASH->value]);

        $map = $this->mapper->map($account);

        $this->assertSame('Bank', $map['AccountType']);
        $this->assertSame('CashOnHand', $map['AccountSubType']);
        $this->assertSame('bank', $this->mapper->section($account));
    }

    public function test_maps_accounts_receivable_to_qbo_accounts_receivable_type(): void
    {
        $account = Account::factory()->create(['type' => 'asset', 'subtype' => AccountSubtype::ACCOUNTS_RECEIVABLE->value]);

        $this->assertSame('Accounts Receivable', $this->mapper->qboType($account));
        $this->assertSame('accounts_receivable', $this->mapper->section($account));
    }

    public function test_maps_accounts_payable_to_qbo_accounts_payable_type(): void
    {
        $account = Account::factory()->create(['type' => 'liability', 'subtype' => AccountSubtype::ACCOUNTS_PAYABLE->value]);

        $this->assertSame('Accounts Payable', $this->mapper->qboType($account));
        $this->assertSame('accounts_payable', $this->mapper->section($account));
    }

    public function test_maps_cogs_subtype_to_qbo_cost_of_goods_sold(): void
    {
        $account = Account::factory()->create(['type' => 'expense', 'subtype' => AccountSubtype::COST_OF_GOODS_SOLD->value]);

        $this->assertSame('Cost of Goods Sold', $this->mapper->qboType($account));
        $this->assertSame('cogs', $this->mapper->section($account));
    }

    public function test_maps_contra_revenue_subtype_to_qbo_discounts_refunds_given(): void
    {
        $account = Account::factory()->create(['type' => 'revenue', 'subtype' => AccountSubtype::CONTRA_REVENUE->value]);

        $this->assertSame('Income', $this->mapper->qboType($account));
        $this->assertSame('DiscountsRefundsGiven', $this->mapper->qboSubType($account));
        $this->assertSame('income', $this->mapper->section($account));
    }

    public function test_falls_back_to_account_type_when_subtype_is_unmapped(): void
    {
        $account = Account::factory()->create(['type' => 'revenue', 'subtype' => null]);

        $map = $this->mapper->map($account);

        $this->assertSame('Income', $map['AccountType']);
    }

    public function test_map_accepts_a_backed_enum_directly(): void
    {
        $map = $this->mapper->map(AccountSubtype::RENT_EXPENSE);

        $this->assertSame('Expense', $map['AccountType']);
        $this->assertSame('Rent', $map['AccountSubType']);
    }

    public function test_map_falls_back_to_other_current_asset_for_completely_unknown_input(): void
    {
        $map = $this->mapper->map('not-a-real-subtype');

        $this->assertSame('Other Current Asset', $map['AccountType']);
    }
}
