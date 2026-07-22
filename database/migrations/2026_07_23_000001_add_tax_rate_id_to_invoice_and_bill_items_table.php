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

        Schema::connection($connection)->table($prefix . 'invoice_items', function (Blueprint $table) use ($prefix): void {
            $table->foreignId('tax_rate_id')->nullable()->after('tax_rate')
                ->constrained($prefix . 'tax_rates')->onDelete('set null');
        });

        Schema::connection($connection)->table($prefix . 'bill_items', function (Blueprint $table) use ($prefix): void {
            $table->foreignId('tax_rate_id')->nullable()->after('tax_rate')
                ->constrained($prefix . 'tax_rates')->onDelete('set null');
        });

        Schema::connection($connection)->table($prefix . 'tax_rates', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'invoice_items', function (Blueprint $table) use ($prefix): void {
            $table->dropForeign([$prefix . 'invoice_items_tax_rate_id_foreign']);
            $table->dropColumn('tax_rate_id');
        });

        Schema::connection($connection)->table($prefix . 'bill_items', function (Blueprint $table) use ($prefix): void {
            $table->dropForeign([$prefix . 'bill_items_tax_rate_id_foreign']);
            $table->dropColumn('tax_rate_id');
        });

        Schema::connection($connection)->table($prefix . 'tax_rates', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
