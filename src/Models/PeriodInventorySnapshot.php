<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class PeriodInventorySnapshot extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'period_inventory_snapshots';
    }

    protected $fillable = [
        'fiscal_period_id',
        'warehouse_code',
        'warehouse_name',
        'product_sku',
        'product_name',
        'qty_on_hand',
        'wac_amount',
        'total_value',
        'currency',
        'snapshot_date',
    ];

    protected $casts = [
        'qty_on_hand'   => 'decimal:4',
        'wac_amount'    => 'decimal:4',
        'total_value'   => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }
}
