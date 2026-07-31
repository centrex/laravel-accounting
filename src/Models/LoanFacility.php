<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Tracks any organizational loan — term, working-capital, inter-company, director, equipment, etc.
 *
 * Each facility owns two dedicated GL sub-accounts (principal payable + accrued interest)
 * and an optional SBU code so interest expense is attributed to the correct business unit.
 *
 * The loan is drawn down and its interest accrued in `currency` (not necessarily the
 * accounting base currency) — `exchange_rate` converts that to base currency for GL
 * posting, so the *Local() accessors below reflect the lender's currency while
 * outstandingPrincipal()/accruedInterest() reflect what the GL accounts actually hold.
 *
 * loan_term determines the account range:
 *   short_term  → principal 240x, accrued interest 242x, expense 6720
 *   long_term   → principal 250x, accrued interest 252x, expense 6730
 */
class LoanFacility extends Model implements Auditable
{
    use AuditableTrait;
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
            'exchange_rate' => 'float',
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

    /** Current outstanding principal from the GL, in the accounting base currency. */
    public function outstandingPrincipal(): float
    {
        return $this->principalAccount?->getCurrentBalance() ?? 0.0;
    }

    /** Current outstanding principal converted into the loan's own currency (loan_facilities.currency). */
    public function outstandingPrincipalLocal(): float
    {
        return round($this->outstandingPrincipal() / $this->rate(), 2);
    }

    /** Current accrued but unpaid interest from the GL, in the accounting base currency. */
    public function accruedInterest(): float
    {
        return $this->interestAccount?->getCurrentBalance() ?? 0.0;
    }

    /** Current accrued but unpaid interest converted into the loan's own currency. */
    public function accruedInterestLocal(): float
    {
        return round($this->accruedInterest() / $this->rate(), 2);
    }

    /**
     * Monthly interest amount, in the loan's own currency.
     * Interest is charged by the lender on the local-currency principal, not the base-currency
     * equivalent — see Accounting::accrueLoanInterest(), which posts this converted to base currency.
     */
    public function monthlyInterestAmount(): float
    {
        return round($this->outstandingPrincipalLocal() * $this->monthly_rate, 2);
    }

    private function rate(): float
    {
        return $this->exchange_rate > 0 ? $this->exchange_rate : 1.0;
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
