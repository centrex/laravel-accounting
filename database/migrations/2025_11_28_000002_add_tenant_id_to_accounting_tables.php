<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Tables that are root aggregates and require per-tenant isolation. */
    private function rootTables(string $prefix): array
    {
        return [
            $prefix . 'accounts',
            $prefix . 'journal_entries',
            $prefix . 'fiscal_years',
            $prefix . 'fiscal_periods',
            $prefix . 'account_balances',
            $prefix . 'customers',
            $prefix . 'vendors',
            $prefix . 'invoices',
            $prefix . 'bills',
            $prefix . 'expenses',
            $prefix . 'budgets',
            $prefix . 'tax_rates',
            $prefix . 'payments',
            $prefix . 'inventory_financing_facilities',
            $prefix . 'loan_facilities',
        ];
    }

    public function up(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        foreach ($this->rootTables($prefix) as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        foreach ($this->rootTables($prefix) as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $t): void {
                $t->dropColumn('tenant_id');
            });
        }
    }
};
