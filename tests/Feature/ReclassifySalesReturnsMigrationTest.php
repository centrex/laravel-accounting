<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The migrate() call in TestCase::setUp() already runs
 * 2026_09_03_000001_reclassify_sales_discounts_and_returns_as_contra_revenue.php
 * against an empty accounts table (a no-op there) — this test exercises it
 * directly against rows shaped like an already-provisioned tenant's chart of
 * accounts, seeded under the old type: 'expense' classification.
 */
class ReclassifySalesReturnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_reclassifies_existing_accounts_and_down_reverts_them(): void
    {
        foreach (['6130', '6134'] as $code) {
            Account::factory()->create(['code' => $code, 'type' => 'expense', 'subtype' => 'selling_expense']);
        }
        // A code outside the reclassified set — must be untouched either way.
        Account::factory()->create(['code' => '6100', 'type' => 'expense', 'subtype' => 'rent_expense']);

        $migration = $this->loadMigration();

        $migration->up();

        $this->assertDatabaseHas('acct_accounts', ['code' => '6130', 'type' => 'revenue', 'subtype' => 'contra_revenue']);
        $this->assertDatabaseHas('acct_accounts', ['code' => '6134', 'type' => 'revenue', 'subtype' => 'contra_revenue']);
        $this->assertDatabaseHas('acct_accounts', ['code' => '6100', 'type' => 'expense', 'subtype' => 'rent_expense']);

        $migration->down();

        $this->assertDatabaseHas('acct_accounts', ['code' => '6130', 'type' => 'expense', 'subtype' => 'selling_expense']);
        $this->assertDatabaseHas('acct_accounts', ['code' => '6134', 'type' => 'expense', 'subtype' => 'selling_expense']);
    }

    public function test_up_is_a_no_op_for_an_account_already_reclassified_by_hand(): void
    {
        Account::factory()->create(['code' => '6131', 'type' => 'revenue', 'subtype' => 'operating_revenue']);

        $this->loadMigration()->up();

        // The 'AND type = expense' guard means an account someone already moved to
        // 'revenue' under a different subtype is left alone, not force-relabeled.
        $this->assertDatabaseHas('acct_accounts', ['code' => '6131', 'type' => 'revenue', 'subtype' => 'operating_revenue']);
    }

    private function loadMigration(): Migration
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/2026_09_03_000001_reclassify_sales_discounts_and_returns_as_contra_revenue.php';

        /** @var Migration $migration */
        $migration = require $path;

        return $migration;
    }
}
