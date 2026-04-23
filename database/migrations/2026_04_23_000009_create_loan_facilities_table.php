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

        Schema::create("{$prefix}loan_facilities", function (Blueprint $table): void {
            $table->id();
            $table->string('lender_name');

            // term_loan | working_capital | inter_company | director | equipment | overdraft | bridge
            $table->string('loan_type')->default('term_loan');

            // short_term (< 12 months) → uses 240x/242x accounts
            // long_term  (≥ 12 months) → uses 250x/252x accounts
            $table->string('loan_term')->default('short_term');

            $table->string('lender_contact')->nullable();

            // Optional SBU — all journal entries for this facility are tagged with this code
            $table->string('sbu_code', 20)->nullable();

            $table->unsignedBigInteger('principal_account_id');  // sub-account under 2400 or 2500
            $table->unsignedBigInteger('interest_account_id');   // sub-account under 2420 or 2520

            $table->decimal('monthly_rate', 8, 6)->default(0.020000);
            $table->decimal('loan_amount', 15, 2)->nullable();   // original sanctioned amount
            $table->date('disbursed_at')->nullable();
            $table->date('due_at')->nullable();
            $table->unsignedSmallInteger('tenure_months')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('loan_type');
            $table->index('loan_term');
            $table->index('sbu_code');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        Schema::dropIfExists("{$prefix}loan_facilities");
    }
};
