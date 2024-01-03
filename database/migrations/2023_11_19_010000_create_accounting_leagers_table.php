<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // One of: 'asset', 'liability', 'equity', 'income', 'expense'
            $table->string('type', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_ledgers');
    }
};
