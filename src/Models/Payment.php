<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Payment extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    /**
     * payment_method values that settle a payable without any cash or bank movement.
     *
     * Applying a credit memo to an invoice records a Payment row so the invoice's paid
     * amount and the customer ledger stay consistent, but the cash was already accounted
     * for when the memo itself was issued — treating that row as a collection would
     * double-count it in any cash-based figure.
     */
    public const NON_CASH_PAYMENT_METHODS = ['credit_memo'];

    protected function getTableSuffix(): string
    {
        return 'payments';
    }

    /**
     * Restrict to payments that moved real cash or bank funds.
     *
     * The single place that knows which payment methods are non-cash, so callers computing
     * cash-based figures (the cash-flow run rate, cash book) stay correct as new non-cash
     * settlement methods are added.
     */
    public function scopeCashMovement(Builder $query): Builder
    {
        return $query->whereNotIn('payment_method', self::NON_CASH_PAYMENT_METHODS);
    }

    protected $fillable = [
        'payment_number', 'payable_type', 'payable_id',
        'payment_date', 'amount', 'payment_method',
        'reference', 'notes', 'journal_entry_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:2',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
