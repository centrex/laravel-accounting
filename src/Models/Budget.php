<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Models;

use Centrex\Accounting\Concerns\AddTablePrefix;
use Centrex\Accounting\Models\Expense;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Facades\DB;

class Budget extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'budgets';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('accounting.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'budget_number', 'name', 'fiscal_year_id', 'period_start', 'period_end',
        'total_amount', 'currency', 'status', 'notes', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'total_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $budget): void {
            if ($budget->budget_number) {
                return;
            }

            DB::connection($budget->getConnectionName())->transaction(function () use ($budget) {
                $date = now()->format('Ymd');

                $lastBudget = self::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($lastBudget && preg_match('/(\d+)$/', $lastBudget->budget_number, $m)) {
                    $sequence = ((int) $m[1]) + 1;
                }

                $budget->budget_number = sprintf(
                    'BUD-%s-%05d',
                    $date,
                    $sequence,
                );
            });
        });
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function getTotalAllocatedAttribute(): float
    {
        return (float) $this->items->sum('amount');
    }

    public function getRemainingAttribute(): float
    {
        return (float) $this->total_amount - $this->total_allocated;
    }

    public function approve(?int $userId = null): void
    {
        $this->update([
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);
    }

    public function getSpentForAccount(int $accountId): float
    {
        $actualExpense = Expense::query()
            ->where('account_id', $accountId)
            ->whereBetween('expense_date', [$this->period_start, $this->period_end])
            ->whereIn('status', ['approved', 'paid'])
            ->sum('total');

        return (float) $actualExpense;
    }

    public function getVarianceForAccount(int $accountId): float
    {
        $budgeted = $this->items()->where('account_id', $accountId)->value('amount') ?? 0;
        $spent = $this->getSpentForAccount($accountId);

        return (float) $budgeted - $spent;
    }
}
