<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\JvStatus;
use Centrex\Accounting\Exceptions\{InvalidStatusTransitionException, UnbalancedJournalException};
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
        'approved_at', 'status', 'source_type', 'source_id', 'source_action', 'sbu_code',
    ];

    protected $casts = [
        'date'          => 'date',
        'status'        => JvStatus::class,
        'approved_at'   => 'datetime',
        'exchange_rate' => 'decimal:6',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /** Check whether debits equal credits within the configured rounding tolerance. */
    public function isBalanced(): bool
    {
        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);

        // Use already-loaded lines collection when available to avoid extra queries
        if ($this->relationLoaded('lines')) {
            $debits = $this->lines->where('type', 'debit')->sum('amount');
            $credits = $this->lines->where('type', 'credit')->sum('amount');
        } else {
            $debits = $this->lines()->where('type', 'debit')->sum('amount');
            $credits = $this->lines()->where('type', 'credit')->sum('amount');
        }

        return abs((float) $debits - (float) $credits) < $tolerance;
    }

    /** Post the entry: validates balance, sets status → posted. */
    public function post(): bool
    {
        if (!$this->isBalanced()) {
            throw UnbalancedJournalException::make($this);
        }

        if ($this->status === JvStatus::POSTED || $this->status?->value === 'posted') {
            throw InvalidStatusTransitionException::make('JournalEntry', 'posted', 'posted');
        }

        $this->update([
            'status'      => 'posted',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return true;
    }

    /** Void a posted entry. */
    public function void(): bool
    {
        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;

        if ($statusValue !== 'posted') {
            throw InvalidStatusTransitionException::make('JournalEntry', $statusValue, 'void');
        }

        $this->update(['status' => 'void']);

        return true;
    }

    /** Relationships — kept after moving away from App\Models\User to avoid coupling */
    public function creator(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);

        return $this->belongsTo($userModel, 'created_by');
    }

    public function approver(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);

        return $this->belongsTo($userModel, 'approved_by');
    }
}
