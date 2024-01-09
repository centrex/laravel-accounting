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

        Schema::connection($connection)->create('accounting_journals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ledger_id')->nullable();
            $table->foreign('ledger_id')->references('id')->on('accounting_ledgers');

            $table->bigInteger('balance');
            $table->string('currency_code', 3);

            // @todo need some indexes.
            $table->string('morphed_type', 60);
            $table->bigInteger('morphed_id')->unsigned();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('accounting.drivers.database.connection', config('database.default'));
        
        Schema::connection($connection)->dropIfExists('accounting_journals');
    }
};
