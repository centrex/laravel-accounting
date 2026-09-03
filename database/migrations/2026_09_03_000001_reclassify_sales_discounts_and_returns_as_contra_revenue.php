<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales discount/return accounts (6130-6134) were seeded as type: 'expense',
 * landing below Gross Profit on the income statement instead of netting
 * against Sales Revenue (4000) as IFRS 15 requires for variable
 * consideration. Accounting::initializeChartOfAccounts()/AccountingSeeder
 * now seed these as type: 'revenue', subtype: 'contra_revenue' for fresh
 * installs — this migration reclassifies already-provisioned accounts,
 * since Account::firstOrCreate()-based seeding never updates an existing
 * row. See AccountSubtype::CONTRA_REVENUE.
 */
return new class() extends Migration
{
    private const CODES = ['6130', '6131', '6132', '6133', '6134'];

    public function up(): void
    {
        $this->accountsTable()
            ->whereIn('code', self::CODES)
            ->where('type', 'expense')
            ->update(['type' => 'revenue', 'subtype' => 'contra_revenue']);
    }

    public function down(): void
    {
        $this->accountsTable()
            ->whereIn('code', self::CODES)
            ->where('type', 'revenue')
            ->where('subtype', 'contra_revenue')
            ->update(['type' => 'expense', 'subtype' => 'selling_expense']);
    }

    private function accountsTable(): Illuminate\Database\Query\Builder
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $prefix = is_string($prefix) ? $prefix : 'acct_';

        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $connection = is_string($connection) ? $connection : null;

        return DB::connection($connection)->table($prefix . 'accounts');
    }
};
