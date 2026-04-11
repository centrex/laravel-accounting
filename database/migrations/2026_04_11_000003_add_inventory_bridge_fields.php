<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        if (!Schema::connection($connection)->hasColumn($prefix . 'journal_entries', 'source_type')) {
            Schema::connection($connection)->table($prefix . 'journal_entries', function (Blueprint $table) use ($prefix): void {
                $table->string('source_type', 150)->nullable()->after('status');
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->string('source_action', 50)->nullable()->after('source_id');

                $table->index(['source_type', 'source_id'], $prefix . 'journal_entries_source_idx');
                $table->index(['source_type', 'source_id', 'source_action'], $prefix . 'journal_entries_source_action_idx');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'invoices', 'inventory_sale_order_id')) {
            Schema::connection($connection)->table($prefix . 'invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('inventory_sale_order_id')->nullable()->after('journal_entry_id');
                $table->index('inventory_sale_order_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'bills', 'inventory_purchase_order_id')) {
            Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
                $table->unsignedBigInteger('inventory_purchase_order_id')->nullable()->after('journal_entry_id');
                $table->index('inventory_purchase_order_id');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'bills_inventory_purchase_order_id_index');
            $table->dropColumn('inventory_purchase_order_id');
        });

        Schema::connection($connection)->table($prefix . 'invoices', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'invoices_inventory_sale_order_id_index');
            $table->dropColumn('inventory_sale_order_id');
        });

        Schema::connection($connection)->table($prefix . 'journal_entries', function (Blueprint $table) use ($prefix): void {
            $table->dropIndex($prefix . 'journal_entries_source_idx');
            $table->dropIndex($prefix . 'journal_entries_source_action_idx');
            $table->dropColumn(['source_type', 'source_id', 'source_action']);
        });
    }
};
