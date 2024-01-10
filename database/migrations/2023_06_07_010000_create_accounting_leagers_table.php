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

        Schema::connection($connection)->create('accounting_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // One of: 'asset', 'liability', 'equity', 'income', 'expense'
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists('accounting_ledgers');
    }
};
