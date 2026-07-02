<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'expenses', function (Blueprint $table): void {
            $table->string('payment_account_code', 50)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'expenses', function (Blueprint $table): void {
            $table->dropColumn('payment_account_code');
        });
    }
};
