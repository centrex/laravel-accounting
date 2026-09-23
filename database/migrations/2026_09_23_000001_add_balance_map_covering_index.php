<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every financial report is ultimately one aggregate: join journal_entry_lines to their
 * posted entries, then SUM the debit and credit sides grouped by account.
 *
 * Driving from the entry side, that join reads journal_entry_lines by journal_entry_id and
 * then needs account_id, type and amount off each row. The existing
 * (journal_entry_id, account_id) index supplies only the first two, so every line still
 * costs a lookup back into the table for type and amount. Carrying all four columns makes
 * the aggregate index-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = $this->schema();
        $table = $this->table();
        $index = $this->indexName();

        if ($this->hasIndex($schema, $table, $index)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->index(['journal_entry_id', 'type', 'account_id', 'amount'], $index);
        });
    }

    public function down(): void
    {
        $schema = $this->schema();
        $table = $this->table();
        $index = $this->indexName();

        if (!$this->hasIndex($schema, $table, $index)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    private function schema(): Illuminate\Database\Schema\Builder
    {
        $connection = config('accounting.drivers.database.connection')
            ?? config('database.default');

        return Schema::connection(is_string($connection) ? $connection : null);
    }

    private function table(): string
    {
        $prefix = config('accounting.table_prefix');

        return (is_string($prefix) && $prefix !== '' ? $prefix : 'acct_') . 'journal_entry_lines';
    }

    private function indexName(): string
    {
        return 'jel_entry_type_account_amount_idx';
    }

    private function hasIndex(Illuminate\Database\Schema\Builder $schema, string $table, string $indexName): bool
    {
        return collect($schema->getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);
    }
};
