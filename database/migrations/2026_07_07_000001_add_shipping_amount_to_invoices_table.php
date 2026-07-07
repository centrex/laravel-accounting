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

        Schema::connection($connection)->table($prefix . 'invoices', function (Blueprint $table): void {
            $table->decimal('shipping_amount', 18, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'invoices', function (Blueprint $table): void {
            $table->dropColumn('shipping_amount');
        });
    }
};
