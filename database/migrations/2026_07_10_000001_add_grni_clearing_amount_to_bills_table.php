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
            // Amount already capitalized to Inventory Asset via a goods-received posting (e.g. an
            // inventory GRN) for the same goods this bill covers. When the bill is posted, this
            // amount is cleared against the GRNI liability instead of being debited to Inventory
            // a second time — see Accounting::postBill().
            $table->decimal('grni_clearing_amount', 18, 2)->default(0)->after('other_charges_amount');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'bills', function (Blueprint $table): void {
            $table->dropColumn('grni_clearing_amount');
        });
    }
};
