<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\JvStatus;
use Centrex\Accounting\Exceptions\{AccountingException, InvalidStatusTransitionException, UnbalancedJournalException};
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
        'approved_at', 'submitted_by', 'submitted_at', 'reviewer_note',
        'status', 'source_type', 'source_id', 'source_action', 'sbu_code',
    ];

    protected $casts = [
        'date'          => 'date',
        'status'        => JvStatus::class,
        'approved_at'   => 'datetime',
        'submitted_at'  => 'datetime',
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

    /** Submit a draft entry for approval (Draft → Submitted). */
    public function submit(): bool
    {
        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;

        if ($statusValue !== 'draft') {
            throw InvalidStatusTransitionException::make('JournalEntry', $statusValue, 'submitted');
        }

        if (!$this->isBalanced()) {
            throw UnbalancedJournalException::make($this);
        }

        $this->update([
            'status'       => 'submitted',
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
        ]);

        return true;
    }

    /** Return a submitted entry to draft (Submitted → Draft). */
    public function returnToDraft(?string $note = null): bool
    {
        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;

        if ($statusValue !== 'submitted') {
            throw InvalidStatusTransitionException::make('JournalEntry', $statusValue, 'draft');
        }

        $this->update([
            'status'        => 'draft',
            'submitted_by'  => null,
            'submitted_at'  => null,
            'reviewer_note' => $note,
        ]);

        return true;
    }

    /** Post the entry: validates balance, checks period lock, sets status → posted. */
    public function post(bool $bypassPeriodLock = false): bool
    {
        if (!$this->isBalanced()) {
            throw UnbalancedJournalException::make($this);
        }

        $statusValue = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;

        if (!in_array($statusValue, ['draft', 'submitted'])) {
            throw InvalidStatusTransitionException::make('JournalEntry', $statusValue, 'posted');
        }

        if (!$bypassPeriodLock && config('accounting.enforce_period_lock', true)) {
            $isClosed = FiscalPeriod::query()
                ->where('is_closed', true)
                ->whereDate('start_date', '<=', $this->date)
                ->whereDate('end_date', '>=', $this->date)
                ->exists();

            if ($isClosed) {
                throw new AccountingException(
                    "Cannot post to a closed period. Entry date {$this->date->format('Y-m-d')} falls in a locked accounting period."
                );
            }
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

    public function submitter(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \Illuminate\Foundation\Auth\User::class);

        return $this->belongsTo($userModel, 'submitted_by');
    }
}
