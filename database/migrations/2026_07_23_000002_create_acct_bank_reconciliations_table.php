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

        Schema::connection($connection)->create($prefix . 'bank_reconciliations', function (Blueprint $table) use ($prefix, $withUserForeignKeys): void {
            $table->id();
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->date('statement_date');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('statement_ending_balance', 18, 2)->default(0);
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_id');
            $table->index('status');

            if ($withUserForeignKeys) {
                $table->foreign('reconciled_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::connection($connection)->create($prefix . 'bank_statement_lines', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained($prefix . 'bank_reconciliations')->onDelete('cascade');
            $table->date('transaction_date');
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->string('type'); // debit | credit
            $table->string('external_reference')->nullable();
            $table->foreignId('matched_journal_entry_line_id')->nullable()
                ->constrained($prefix . 'journal_entry_lines')->onDelete('set null');
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index('bank_reconciliation_id');
        });

        Schema::connection($connection)->table($prefix . 'journal_entry_lines', function (Blueprint $table) use ($prefix): void {
            $table->foreignId('bank_reconciliation_id')->nullable()->after('reference')
                ->constrained($prefix . 'bank_reconciliations')->onDelete('set null');
            $table->timestamp('reconciled_at')->nullable()->after('bank_reconciliation_id');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'journal_entry_lines', function (Blueprint $table) use ($prefix): void {
            $table->dropForeign([$prefix . 'journal_entry_lines_bank_reconciliation_id_foreign']);
            $table->dropColumn(['bank_reconciliation_id', 'reconciled_at']);
        });

        Schema::connection($connection)->dropIfExists($prefix . 'bank_statement_lines');
        Schema::connection($connection)->dropIfExists($prefix . 'bank_reconciliations');
    }
};
