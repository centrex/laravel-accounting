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
        $withUserForeignKeys = (bool) config('accounting.user_foreign_keys', false);

        Schema::connection($connection)->create($prefix . 'fixed_assets', function (Blueprint $table) use ($prefix, $withUserForeignKeys): void {
            $table->id();
            $table->string('asset_code')->unique();
            $table->string('name');
            $table->string('asset_class', 100)->nullable();
            $table->string('sbu_code', 20)->nullable();

            $table->foreignId('asset_account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->foreignId('accumulated_depreciation_account_id')->constrained($prefix . 'accounts')->onDelete('restrict');

            $table->decimal('acquisition_cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 30)->default('straight_line');

            $table->date('acquired_at');
            $table->date('disposed_at')->nullable();
            $table->decimal('disposal_proceeds', 18, 2)->nullable();
            $table->foreignId('disposal_journal_entry_id')->nullable()->constrained($prefix . 'journal_entries')->onDelete('set null');

            $table->string('location')->nullable();
            $table->string('serial_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('asset_class');
            $table->index('disposed_at');
            $table->index('sbu_code', $prefix . 'fixed_assets_sbu_idx');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('disposed_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'fixed_assets');
    }
};
