<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $prefix;

    public function __construct()
    {
        $connection = config('accounting.drivers.database.connection');
        if ($connection) {
            $this->connection = $connection;
        }
        $this->prefix = config('accounting.table_prefix', 'acct_');
    }

    public function up(): void
    {
        Schema::table($this->prefix . 'journal_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('submitted_by')->nullable()->after('approved_at');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->text('reviewer_note')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix . 'journal_entries', function (Blueprint $table): void {
            $table->dropColumn(['submitted_by', 'submitted_at', 'reviewer_note']);
        });
    }
};
