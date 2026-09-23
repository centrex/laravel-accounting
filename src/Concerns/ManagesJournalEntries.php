<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Exceptions\UnbalancedJournalException;
use Centrex\Accounting\Models\JournalEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ManagesJournalEntries
{
    /**
     * Create a balanced journal entry with lines.
     *
     * @param  array{date: string, reference?: string, type?: string, description?: string,
     *               currency?: string, exchange_rate?: float, sbu_code?: string, lines: list<array{account_id: int,
     *               type: string, amount: float, description?: string, reference?: string}>} $data
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        $data = $this->normalizeJournalPayload($data);
        $data['sbu_code'] = $this->normalizeSbuCode($data['sbu_code'] ?? null);
        $usesGeneratedEntryNumber = empty($data['entry_number']);

        return DB::transaction(function () use ($data, $usesGeneratedEntryNumber): JournalEntry {
            $attempts = $usesGeneratedEntryNumber ? 5 : 1;
            $lastException = null;

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                try {
                    $entry = JournalEntry::create([
                        'entry_number'  => $data['entry_number'] ?? $this->generateJournalEntryNumber(),
                        'date'          => $data['date'],
                        'reference'     => $data['reference'] ?? null,
                        'type'          => $data['type'] ?? 'general',
                        'description'   => $data['description'] ?? null,
                        'currency'      => $data['currency'] ?? config('accounting.base_currency', 'BDT'),
                        'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                        'created_by'    => $data['created_by'] ?? auth()->id(),
                        'status'        => $data['status'] ?? 'draft',
                        'source_type'   => $data['source_type'] ?? null,
                        'source_id'     => $data['source_id'] ?? null,
                        'source_action' => $data['source_action'] ?? null,
                        'sbu_code'      => $data['sbu_code'] ?? null,
                    ]);

                    foreach ($data['lines'] as $line) {
                        $entry->lines()->create([
                            'account_id'  => $line['account_id'],
                            'type'        => strtolower((string) $line['type']),
                            'amount'      => $line['amount'],
                            'description' => $line['description'] ?? null,
                            'reference'   => $line['reference'] ?? null,
                        ]);
                    }

                    if (!$entry->isBalanced()) {
                        throw UnbalancedJournalException::make($entry);
                    }

                    return $entry;
                } catch (QueryException $exception) {
                    if (!$usesGeneratedEntryNumber || !$this->isDuplicateJournalEntryNumberException($exception)) {
                        throw $exception;
                    }

                    $lastException = $exception;
                }
            }

            throw $lastException ?? new \RuntimeException('Unable to generate a unique journal entry number.');
        });
    }

    protected function generateJournalEntryNumber(): string
    {
        return 'JE-' . now()->format('YmdHis') . '-' . Str::lower(Str::random(8));
    }

    protected function isDuplicateJournalEntryNumberException(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        // MySQL reports code 1062 with the named unique index in the message;
        // SQLite reports code 19 with a plain "table.column" constraint message.
        $isDuplicateKeyError = in_array($driverCode, [1062, 19], true);

        return $isDuplicateKeyError && str_contains($message, 'entry_number');
    }
}
