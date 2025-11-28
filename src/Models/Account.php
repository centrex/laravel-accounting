<?php

namespace Centrex\LaravelAccounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Account Model
class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'subtype', 'parent_id', 
        'description', 'currency', 'is_active', 'is_system', 'level'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'level' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    // Get current balance
    public function getCurrentBalance(): float
    {
        $debits = $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            })
            ->where('type', 'debit')
            ->sum('amount');

        $credits = $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            })
            ->where('type', 'credit')
            ->sum('amount');

        // Normal balance depends on account type
        if (in_array($this->type, ['asset', 'expense'])) {
            return $debits - $credits; // Debit normal balance
        } else {
            return $credits - $debits; // Credit normal balance
        }
    }

    // Check if account has normal debit balance
    public function isDebitAccount(): bool
    {
        return in_array($this->type, ['asset', 'expense']);
    }
}