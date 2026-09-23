<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RequisitionItem extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'requisition_items';
    }

    protected $fillable = [
        'requisition_id', 'description', 'quantity', 'unit_price', 'total',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
