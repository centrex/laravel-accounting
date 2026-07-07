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

        Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 2)->default(0)->after('tax_amount');
            $table->decimal('shipping_amount', 18, 2)->default(0)->after('discount_amount');
            $table->decimal('other_charges_amount', 18, 2)->default(0)->after('shipping_amount');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
            $table->dropColumn(['discount_amount', 'shipping_amount', 'other_charges_amount']);
        });
    }
};
