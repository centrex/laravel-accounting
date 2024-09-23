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

        Schema::connection($connection)->create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 3)->unique();
            $table->string('type', 30);
            $table->boolean('is_cash')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists('accounts');
    }
};
