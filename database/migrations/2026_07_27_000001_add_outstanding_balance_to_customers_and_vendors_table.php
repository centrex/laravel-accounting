<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'customers', function (Blueprint $table): void {
            $table->decimal('outstanding_balance', 18, 2)->default(0)->after('credit_limit');
        });

        $schema->table($prefix . 'vendors', function (Blueprint $table): void {
            $table->decimal('outstanding_balance', 18, 2)->default(0)->after('payment_terms');
        });

        // Backfill from existing non-settled, non-void, non-draft documents.
        // Written as a plain (non-aliased) correlated subquery so it runs unchanged
        // on both MySQL and SQLite (used by the package's test suite).
        DB::connection($connection)->statement(<<<SQL
            UPDATE `{$prefix}customers`
            SET outstanding_balance = (
                SELECT COALESCE(SUM(i.total - i.paid_amount), 0)
                FROM `{$prefix}invoices` i
                WHERE i.customer_id = `{$prefix}customers`.id
                  AND i.status NOT IN ('settled', 'draft', 'void')
            )
        SQL);

        DB::connection($connection)->statement(<<<SQL
            UPDATE `{$prefix}vendors`
            SET outstanding_balance = (
                SELECT COALESCE(SUM(b.total - b.paid_amount), 0)
                FROM `{$prefix}bills` b
                WHERE b.vendor_id = `{$prefix}vendors`.id
                  AND b.status NOT IN ('settled', 'draft', 'void')
            )
        SQL);
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'customers', function (Blueprint $table): void {
            $table->dropColumn('outstanding_balance');
        });

        $schema->table($prefix . 'vendors', function (Blueprint $table): void {
            $table->dropColumn('outstanding_balance');
        });
    }
};
