<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'journal_entry_lines';
    }

    protected $fillable = [
        'journal_entry_id', 'account_id', 'type',
        'amount', 'description', 'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
