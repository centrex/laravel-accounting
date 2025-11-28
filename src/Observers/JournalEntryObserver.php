<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Observers;

use Centrex\LaravelAccounting\Models\JournalEntry;
use Illuminate\Support\Str;

class JournalEntryObserver
{
    public function creating(JournalEntry $entry): void
    {
        if (empty($entry->entry_number)) {
            $entry->entry_number = $this->generateFallbackNumber('JE');
        }
    }

    public function saved(JournalEntry $entry): void
    {
        // ensure persisted if somehow still empty (rare)
        if (empty($entry->entry_number)) {
            $entry->entry_number = $this->generateFallbackNumber('JE');
            $entry->saveQuietly();
        }
    }

    private function generateFallbackNumber(string $prefix = 'JE'): string
    {
        // e.g. JE-20251128-170512-4f3a1b
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            now()->format('Ymd-His'),
            Str::lower(Str::random(6)),
        );
    }
}
