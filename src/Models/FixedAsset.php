<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A registered fixed asset (PPE, IAS 16) with its own dedicated GL cost account (170x)
 * and accumulated-depreciation contra account (180x), auto-provisioned by
 * Accounting::addFixedAsset(). Depreciation is straight-line only in v1.
 */
class FixedAsset extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'fixed_assets';
    }

    /** Specify the connection, since this implements multitenant solution. */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'asset_code', 'name', 'asset_class', 'sbu_code',
        'asset_account_id', 'accumulated_depreciation_account_id',
        'acquisition_cost', 'salvage_value', 'useful_life_months', 'depreciation_method',
        'acquired_at', 'disposed_at', 'disposal_proceeds', 'disposal_journal_entry_id',
        'location', 'serial_number', 'is_active', 'notes', 'created_by', 'disposed_by',
    ];

    protected $casts = [
        'acquisition_cost'   => 'decimal:2',
        'salvage_value'      => 'decimal:2',
        'useful_life_months' => 'integer',
        'acquired_at'        => 'date',
        'disposed_at'        => 'date',
        'disposal_proceeds'  => 'decimal:2',
        'is_active'          => 'boolean',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $asset): void {
            if ($asset->asset_code) {
                return;
            }

            DB::connection($asset->getConnectionName())->transaction(function () use ($asset) {
                $date = now()->format('Ymd');

                $last = self::withTrashed()
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($last && preg_match('/(\d+)$/', $last->asset_code, $m)) {
                    $sequence = ((int) $m[1]) + 1;
                }

                $asset->asset_code = sprintf('FA-%s-%05d', $date, $sequence);
            });
        });
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function disposalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id');
    }

    /** Depreciable base = cost less salvage value. */
    public function depreciableBase(): float
    {
        return round((float) $this->acquisition_cost - (float) $this->salvage_value, 2);
    }

    /** Straight-line monthly depreciation amount. */
    public function monthlyDepreciationAmount(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return round($this->depreciableBase() / $this->useful_life_months, 2);
    }

    /**
     * Live accumulated depreciation from the GL contra account.
     *
     * The contra account is `type: asset` (so Account::isDebitAccount() treats it as
     * debit-normal) but is always credited in substance, so getCurrentBalance()
     * (debits − credits) comes back negative as depreciation accrues — negate it here.
     */
    public function accumulatedDepreciation(): float
    {
        return -($this->accumulatedDepreciationAccount?->getCurrentBalance() ?? 0.0);
    }

    /** Net book value = cost less live accumulated depreciation. */
    public function netBookValue(): float
    {
        return round((float) $this->acquisition_cost - $this->accumulatedDepreciation(), 2);
    }

    public function isFullyDepreciated(): bool
    {
        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);

        return $this->accumulatedDepreciation() >= ($this->depreciableBase() - $tolerance);
    }

    public function isDisposed(): bool
    {
        return $this->disposed_at !== null;
    }
}
