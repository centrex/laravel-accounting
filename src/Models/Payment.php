<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Concerns\HasTenant;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Payment extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;
    use HasTenant;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'payments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
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
