<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $prefix;

    public function __construct()
    {
        $connection = config('accounting.drivers.database.connection');
        if ($connection) {
            $this->connection = $connection;
        }
        $this->prefix = config('accounting.table_prefix', 'acct_');
    }

    public function up(): void
    {
        Schema::create($this->prefix . 'period_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('warehouse_code', 30)->nullable();
            $table->string('warehouse_name', 120)->nullable();
            $table->string('product_sku', 100)->nullable();
            $table->string('product_name', 255)->nullable();
            $table->decimal('qty_on_hand', 14, 4)->default(0);
            $table->decimal('wac_amount', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->char('currency', 3)->default('BDT');
            $table->date('snapshot_date');
            $table->timestamps();

            $table->index('fiscal_period_id', 'acct_pis_fp_idx');
            $table->index(['fiscal_period_id', 'product_sku'], 'acct_pis_fp_sku_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix . 'period_inventory_snapshots');
    }
};
