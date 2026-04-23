<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tracks an inventory financing credit line from a single lender entity.
 * Each facility owns two GL sub-accounts: one for principal payable, one for accrued interest.
 */
class InventoryFinancingFacility extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        return "{$prefix}inventory_financing_facilities";
    }

    protected function casts(): array
    {
        return [
            'monthly_rate' => 'float',
            'credit_limit' => 'float',
            'is_active'    => 'boolean',
        ];
    }

    public function principalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'principal_account_id');
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id');
    }

    /** Current outstanding principal from the GL. */
    public function outstandingPrincipal(): float
    {
        return $this->principalAccount?->getCurrentBalance() ?? 0.0;
    }

    /** Current accrued but unpaid interest from the GL. */
    public function accruedInterest(): float
    {
        return $this->interestAccount?->getCurrentBalance() ?? 0.0;
    }

    /** Monthly interest amount based on current principal balance. */
    public function monthlyInterestAmount(): float
    {
        return round($this->outstandingPrincipal() * $this->monthly_rate, 2);
    }
}
