<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->create($prefix . 'quickbooks_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('realm_id')->unique()->comment('QBO company realm ID');
            $table->text('access_token');
            $table->string('token_type', 20)->default('Bearer');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('meta')->nullable()->comment('Raw company/token metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix     = config('accounting.table_prefix', 'acct_');
        $connection = config('accounting.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'quickbooks_tokens');
    }
};
