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

        Schema::connection($connection)->table($prefix . 'journal_entries', function (Blueprint $table) use ($prefix): void {
            $table->string('sbu_code', 50)->nullable()->after('source_action');
            $table->index('sbu_code', $prefix . 'journal_entries_sbu_idx');
            $table->index(['sbu_code', 'date', 'status'], $prefix . 'journal_entries_sbu_date_status_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'journal_entries', function (Blueprint $table) use ($prefix): void {
            $table->dropIndex($prefix . 'journal_entries_sbu_date_status_idx');
            $table->dropIndex($prefix . 'journal_entries_sbu_idx');
            $table->dropColumn('sbu_code');
        });
    }
};
