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

        Schema::create("{$prefix}inventory_financing_facilities", function (Blueprint $table): void {
            $table->id();
            $table->string('lender_name');
            $table->string('lender_type')->default('bank'); // bank | private | ngo | mfi | other
            $table->string('lender_contact')->nullable();
            $table->unsignedBigInteger('principal_account_id');  // sub-account under 2150
            $table->unsignedBigInteger('interest_account_id');   // sub-account under 2170
            $table->decimal('monthly_rate', 8, 6)->default(0.020000); // 2% default
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('lender_type');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        Schema::dropIfExists("{$prefix}inventory_financing_facilities");
    }
};
