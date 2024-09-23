<?php

declare(strict_types = 1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class CreateUsersTable
 */
class CreateAccountingJournalTransactionsTable extends Migration
{
    /**
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_journal_transactions', function (Blueprint $table): void {
            $table->char('id', 36)->unique();
            $table->char('transaction_group', 36)->nullable();
            $table->integer('journal_id');
            $table->bigInteger('debit')->nullable();
            $table->bigInteger('credit')->nullable();
            $table->char('currency_code', 3);
            $table->text('memo')->nullable();
            $table->text('tags')->nullable();
            $table->char('reference_type', 32)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamps();
            $table->dateTime('post_date');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_journals');
    }
}
