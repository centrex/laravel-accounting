<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use App\Models\User;
use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Centrex\LaravelAccounting\Enums\JvStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class JournalEntry extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'journal_entries';
    }

    /**
     * Specify the connection, since this implements multitenant solution
     * Called via constructor to faciliate testing
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'entry_number', 'date', 'reference', 'type', 'description',
        'currency', 'exchange_rate', 'created_by', 'approved_by',
        'approved_at', 'status',
    ];

    protected $casts = [
        'date'          => 'date',
        'status'        => JvStatus::class,
        'approved_at'   => 'datetime',
        'exchange_rate' => 'decimal:6',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($entry): void {
            if (!$entry->entry_number) {
                $entry->entry_number = 'JE-' . date('Ymd') . '-' . str_pad(
                    static::whereDate('created_at', today())->count() + 1,
                    4,
                    '0',
                    STR_PAD_LEFT,
                );
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Validate double-entry (debits = credits)
    public function isBalanced(): bool
    {
        $debits = $this->lines()->where('type', 'debit')->sum('amount');
        $credits = $this->lines()->where('type', 'credit')->sum('amount');

        return abs($debits - $credits) < 0.01; // Allow for rounding
    }

    // Post journal entry
    public function post(): bool
    {
        if (!$this->isBalanced()) {
            throw new \Exception('Journal entry is not balanced');
        }

        if ($this->status === 'posted') {
            throw new \Exception('Journal entry is already posted');
        }

        $this->update([
            'status'      => 'posted',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return true;
    }

    // Void journal entry
    public function void(): bool
    {
        if ($this->status !== 'posted') {
            throw new \Exception('Only posted entries can be voided');
        }

        $this->update(['status' => 'void']);

        return true;
    }
}
