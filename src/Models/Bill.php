<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Models;

use Centrex\LaravelAccounting\Concerns\AddTablePrefix;
use Centrex\LaravelAccounting\Enums\EntryStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Bill extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'bills';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'bill_number', 'vendor_id', 'bill_date', 'due_date',
        'subtotal', 'tax_amount', 'total', 'paid_amount',
        'currency', 'status', 'notes', 'journal_entry_id',
    ];

    protected $casts = [
        'bill_date'   => 'date',
        'due_date'    => 'date',
        'status'      => EntryStatus::class,
        'subtotal'    => 'decimal:2',
        'tax_amount'  => 'decimal:2',
        'total'       => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')
            ->where('payable_type', self::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function getBalanceAttribute(): float
    {
        return $this->total - $this->paid_amount;
    }
}
