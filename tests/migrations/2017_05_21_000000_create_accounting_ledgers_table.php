<?php

declare(strict_types = 1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountingLedgersTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_ledgers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('type', 20); // ['asset', 'liability', 'equity', 'income', 'expense']
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_ledgers');
    }
}
