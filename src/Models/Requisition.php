<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Enums\{RequisitionStatus, RequisitionType};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Requisition extends Model implements Auditable
{
    use AuditableTrait;
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'requisitions';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'requisition_number', 'type', 'title', 'description',
        'vendor_id', 'account_id', 'requested_by', 'requested_date',
        'required_date', 'total_amount', 'currency', 'status',
        'notes', 'rejection_reason',
        'submitted_by', 'submitted_at',
        'approved_by', 'approved_at',
        'converted_to_type', 'converted_to_id',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'required_date'  => 'date',
        'total_amount'   => 'decimal:2',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
        'type'           => RequisitionType::class,
        'status'         => RequisitionStatus::class,
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $req): void {
            if ($req->requisition_number) {
                return;
            }

            DB::connection($req->getConnectionName())->transaction(function () use ($req): void {
                $prefix = $req->type === RequisitionType::PURCHASE ? 'PRQ' : 'ERQ';
                $date = now()->format('Ymd');

                $last = self::query()
                    ->where('type', $req->type)
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $seq = 1;

                if ($last && preg_match('/(\d+)$/', $last->requisition_number, $m)) {
                    $seq = ((int) $m[1]) + 1;
                }

                $req->requisition_number = sprintf('%s-%s-%05d', $prefix, $date, $seq);
            });
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function submit(?int $userId = null): void
    {
        $this->update([
            'status'       => RequisitionStatus::SUBMITTED,
            'submitted_by' => $userId,
            'submitted_at' => now(),
        ]);
    }

    public function approve(?int $userId = null): void
    {
        $this->update([
            'status'      => RequisitionStatus::APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    public function reject(string $reason, ?int $userId = null): void
    {
        $this->update([
            'status'           => RequisitionStatus::REJECTED,
            'rejection_reason' => $reason,
            'approved_by'      => $userId,
            'approved_at'      => now(),
        ]);
    }

    public function markConverted(string $toType, int $toId): void
    {
        $this->update([
            'status'           => RequisitionStatus::CONVERTED,
            'converted_to_type' => $toType,
            'converted_to_id'  => $toId,
        ]);
    }

    public function isPurchase(): bool
    {
        return $this->type === RequisitionType::PURCHASE;
    }

    public function isExpense(): bool
    {
        return $this->type === RequisitionType::EXPENSE;
    }
}
