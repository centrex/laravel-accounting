<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Centrex\LaravelAccounting\Enums\{AccountSubtype, AccountType};
use Centrex\LaravelAccounting\Mappers\AccountTypeMapper;
use PHPUnit\Framework\TestCase;

class AccountTypeMapperTest extends TestCase
{
    public function test_operating_revenue_maps_to_income(): void
    {
        $result = AccountTypeMapper::fromSubtype(AccountSubtype::OPERATING_REVENUE);
        $this->assertEquals(AccountType::INCOME, $result);
    }

    public function test_expense_subtypes_map_to_expense(): void
    {
        $subs = AccountTypeMapper::toSubtypes(AccountType::EXPENSE);
        $this->assertContains(AccountSubtype::OPERATING_EXPENSE, $subs);
        $this->assertContains(AccountSubtype::COST_OF_GOODS_SOLD, $subs);
    }
}
