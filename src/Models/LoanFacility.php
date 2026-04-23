<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tracks any organizational loan — term, working-capital, inter-company, director, equipment, etc.
 *
 * Each facility owns two dedicated GL sub-accounts (principal payable + accrued interest)
 * and an optional SBU code so interest expense is attributed to the correct business unit.
 *
 * loan_term determines the account range:
 *   short_term  → principal 240x, accrued interest 242x, expense 6720
 *   long_term   → principal 250x, accrued interest 252x, expense 6730
 */
class LoanFacility extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        return "{$prefix}loan_facilities";
    }

    protected function casts(): array
    {
        return [
            'monthly_rate'  => 'float',
            'loan_amount'   => 'float',
            'is_active'     => 'boolean',
            'disbursed_at'  => 'date',
            'due_at'        => 'date',
            'tenure_months' => 'integer',
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

    public function isShortTerm(): bool
    {
        return $this->loan_term === 'short_term';
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

    /** Monthly interest amount based on current outstanding principal. */
    public function monthlyInterestAmount(): float
    {
        return round($this->outstandingPrincipal() * $this->monthly_rate, 2);
    }

    /** Months remaining until due_at, or null if no due date. */
    public function monthsRemaining(): ?int
    {
        if (!$this->due_at) {
            return null;
        }

        return (int) max(0, now()->diffInMonths($this->due_at, false));
    }
}
