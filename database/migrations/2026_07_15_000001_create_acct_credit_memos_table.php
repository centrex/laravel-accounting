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

        Schema::connection($connection)->create($prefix . 'credit_memos', function (Blueprint $table) use ($prefix, $withUserForeignKeys): void {
            $table->id();
            $table->string('credit_memo_number')->unique();
            $table->foreignId('invoice_id')->constrained($prefix . 'invoices')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained($prefix . 'customers')->onDelete('restrict');
            $table->date('credit_memo_date');
            $table->string('reason')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('amount_refunded', 18, 2)->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained($prefix . 'journal_entries')->onDelete('set null');
            // Cross-package reference to the originating inv_sale_returns row — no FK
            // constraint since laravel-inventory may run on a different DB connection.
            $table->string('source_type', 150)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('sbu_code', 50)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index(['source_type', 'source_id'], $prefix . 'credit_memos_source_idx');
            $table->index('sbu_code', $prefix . 'credit_memos_sbu_idx');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'credit_memos');
    }
};
