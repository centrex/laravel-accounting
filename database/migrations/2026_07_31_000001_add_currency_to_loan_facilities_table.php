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

        Schema::connection($connection)->table($prefix . 'loan_facilities', function (Blueprint $table): void {
            // The loan is denominated (drawn down, and interest accrued) in this currency;
            // exchange_rate converts it to the accounting base currency for GL posting —
            // see LoanFacility::outstandingPrincipalLocal() and Accounting::drawdownLoan().
            $table->string('currency', 3)->default('BDT')->after('loan_amount');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000)->after('currency');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'loan_facilities', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
