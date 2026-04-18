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

        Schema::connection($connection)->create($prefix . 'expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_number')->unique();
            // account_id references the accounts table — no FK to allow cross-connection setups
            $table->unsignedBigInteger('account_id')->nullable();
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->string('status')->default('draft');
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->string('vendor_name')->nullable();
            $table->text('notes')->nullable();
            // journal_entry_id links back to the journal_entries table
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expense_date']);
            $table->index('account_id');
            $table->index('expense_date');
        });

        Schema::connection($connection)->create($prefix . 'expense_items', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('expense_id')->constrained($prefix . 'expenses')->onDelete('cascade');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'expense_items');
        Schema::connection($connection)->dropIfExists($prefix . 'expenses');
    }
};
