<?php

declare(strict_types = 1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class CreateUsersTable
 */
class CreateAccountingJournalsTable extends Migration
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
        Schema::create('accounting_journals', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('ledger_id')->nullable();
            $table->bigInteger('balance');
            $table->char('currency_code', 3);
            $table->char('morphed_type', 32);
            $table->integer('morphed_id');
            $table->timestamps();
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
