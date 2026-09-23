<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->create($prefix . 'requisitions', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->string('requisition_number')->unique();
            $table->string('type');                               // purchase | expense
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained($prefix . 'vendors')->onDelete('set null');
            $table->foreignId('account_id')->nullable()->constrained($prefix . 'accounts')->onDelete('set null');
            $table->string('requested_by')->nullable();           // free-text name or user ID
            $table->date('requested_date');
            $table->date('required_date')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->string('converted_to_type')->nullable();      // Bill::class | Expense::class
            $table->unsignedBigInteger('converted_to_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('requested_date');
            $table->index('vendor_id');
        });

        Schema::connection($connection)->create($prefix . 'requisition_items', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('requisition_id')->constrained($prefix . 'requisitions')->onDelete('cascade');
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();

            $table->index('requisition_id');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'requisition_items');
        Schema::connection($connection)->dropIfExists($prefix . 'requisitions');
    }
};
