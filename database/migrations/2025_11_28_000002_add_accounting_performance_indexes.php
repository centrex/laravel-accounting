<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the indexes that were missing from the initial migration and are
 * required for production performance:
 *
 * - (account_id, type) on journal_entry_lines: every trial-balance and
 *   account-balance query filters/groups by both columns.
 *
 * - (payable_type, payable_id) on payments: polymorphic lookup used when
 *   loading payments for an invoice/bill/expense.
 *
 * - (customer_id, status) on invoices: outstanding-balance and invoice-list
 *   queries filter by both.
 *
 * - (vendor_id, status) on bills: same pattern for AP queries.
 *
 * - (account_id, status) on expenses: expense-by-account lookups.
 *
 * - (status, date) on journal_entries: already in initial migration, verified.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        // journal_entry_lines: composite index for aggregation queries
        Schema::connection($connection)->table($prefix . 'journal_entry_lines', function (Blueprint $table): void {
            $table->index(['account_id', 'type'], 'jel_account_type_idx');
        });

        // payments: polymorphic index for payable lookup
        Schema::connection($connection)->table($prefix . 'payments', function (Blueprint $table): void {
            $table->index(['payable_type', 'payable_id'], 'payments_payable_idx');
        });

        // invoices: composite index for customer AR queries
        Schema::connection($connection)->table($prefix . 'invoices', function (Blueprint $table): void {
            $table->index(['customer_id', 'status'], 'invoices_customer_status_idx');
        });

        // bills: composite index for vendor AP queries
        Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
            $table->index(['vendor_id', 'status'], 'bills_vendor_status_idx');
        });

        // expenses: composite index for account + status queries
        Schema::connection($connection)->table($prefix . 'expenses', function (Blueprint $table): void {
            $table->index(['account_id', 'status'], 'expenses_account_status_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'journal_entry_lines', fn (Blueprint $t) => $t->dropIndex('jel_account_type_idx'));
        Schema::connection($connection)->table($prefix . 'payments', fn (Blueprint $t) => $t->dropIndex('payments_payable_idx'));
        Schema::connection($connection)->table($prefix . 'invoices', fn (Blueprint $t) => $t->dropIndex('invoices_customer_status_idx'));
        Schema::connection($connection)->table($prefix . 'bills', fn (Blueprint $t) => $t->dropIndex('bills_vendor_status_idx'));
        Schema::connection($connection)->table($prefix . 'expenses', fn (Blueprint $t) => $t->dropIndex('expenses_account_status_idx'));
    }
};
