<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->create('accounting_journal_transactions', function (Blueprint $table) {
            $table->string('id', 36)->unique();
            $table->string('transaction_group', 36)->nullable();

            $table->unsignedBigInteger('journal_id');
            $table->foreign('journal_id')->references('id')->on('accounting_journals');

            $table->bigInteger('debit')->nullable();
            $table->bigInteger('credit')->nullable();

            $table->string('currency_code', 3);
            $table->text('memo')->nullable();
            $table->text('tags')->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->bigInteger('reference_id')->nullable();
            $table->dateTime('post_date');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists('accounting_journal_transactions');
    }
};
