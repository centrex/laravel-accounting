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

        Schema::connection($connection)->table($prefix . 'expenses', function (Blueprint $table) use ($prefix): void {
            // Polymorphic link — can target Invoice, Bill, or any future document type
            $table->string('chargeable_type', 150)->nullable()->after('journal_entry_id');
            $table->unsignedBigInteger('chargeable_id')->nullable()->after('chargeable_type');
            $table->index(['chargeable_type', 'chargeable_id'], $prefix . 'expenses_chargeable_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'expenses', function (Blueprint $table) use ($prefix): void {
            $table->dropIndex($prefix . 'expenses_chargeable_idx');
            $table->dropColumn(['chargeable_type', 'chargeable_id']);
        });
    }
};
