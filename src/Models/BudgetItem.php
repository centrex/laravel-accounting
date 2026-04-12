<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Inventory\Models\Expense;
use Illuminate\Database\Eloquent\{Collection as EloquentCollection, Model};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    use AddTablePrefix;

    protected $fillable = [
        'budget_id', 'account_id', 'description', 'amount', 'period_start', 'period_end',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'period_start' => 'date',
        'period_end'   => 'date',
    ];

    protected function getTableSuffix(): string
    {
        return 'budget_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Actual amount spent for this budget item's account in the item's period.
     *
     * NOTE: accessing this directly on each item in a loop causes N+1 queries.
     * Use BudgetItem::loadSpentAmounts($items) before iterating to pre-populate
     * the `_spent_cache` attribute and avoid the extra queries.
     */
    public function getSpentAttribute(): float
    {
        // Return cached value if pre-populated by loadSpentAmounts()
        if (array_key_exists('_spent_cache', $this->attributes)) {
            return (float) $this->attributes['_spent_cache'];
        }

        $periodStart = $this->period_start ?? $this->budget?->period_start;
        $periodEnd = $this->period_end ?? $this->budget?->period_end;

        return (float) Expense::query()
            ->where('account_id', $this->account_id)
            ->when($periodStart && $periodEnd, fn ($q) => $q->whereBetween('expense_date', [$periodStart, $periodEnd]))
            ->whereIn('status', ['approved', 'paid'])
            ->sum('total');
    }

    public function getRemainingAttribute(): float
    {
        return (float) $this->amount - $this->spent;
    }

    public function getPercentageUsedAttribute(): float
    {
        return (float) $this->amount === 0.0
            ? 0.0
            : round(($this->spent / (float) $this->amount) * 100, 2);
    }

    /**
     * Bulk-load spent amounts for a collection of BudgetItems in a single query,
     * avoiding the N+1 query when iterating over items and accessing ->spent.
     *
     * Usage:
     *   $items = $budget->items()->with('account')->get();
     *   BudgetItem::loadSpentAmounts($items, $budget->period_start, $budget->period_end);
     *   foreach ($items as $item) { $item->spent; }  // no extra queries
     */
    public static function loadSpentAmounts(
        EloquentCollection $items,
        mixed $defaultStart = null,
        mixed $defaultEnd = null,
    ): void {
        if ($items->isEmpty()) {
            return;
        }

        $accountIds = $items->pluck('account_id')->unique()->values();

        // Single aggregated query
        $spentMap = Expense::whereIn('account_id', $accountIds)
            ->whereIn('status', ['approved', 'paid'])
            ->when(
                $defaultStart && $defaultEnd,
                fn ($q) => $q->whereBetween('expense_date', [$defaultStart, $defaultEnd]),
            )
            ->selectRaw('account_id, SUM(total) as spent_total')
            ->groupBy('account_id')
            ->pluck('spent_total', 'account_id');

        foreach ($items as $item) {
            // Store in attributes array so the accessor can return it without querying
            $item->attributes['_spent_cache'] = (float) ($spentMap->get($item->account_id) ?? 0.0);
        }
    }
}
