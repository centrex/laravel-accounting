<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Exceptions\InvalidStatusTransitionException;
use Centrex\Accounting\Models\{Budget, BudgetItem};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\{DB, Gate};

trait ManagesBudgets
{
    /** Create a budget with line items. */
    public function createBudget(array $data): Budget
    {
        return DB::transaction(function () use ($data): Budget {
            $budget = Budget::create([
                'name'           => $data['name'],
                'fiscal_year_id' => $data['fiscal_year_id'] ?? null,
                'period_start'   => $data['period_start'],
                'period_end'     => $data['period_end'],
                'total_amount'   => $data['total_amount'],
                'currency'       => $data['currency'] ?? config('accounting.base_currency', 'BDT'),
                'status'         => 'draft',
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                $budget->items()->create([
                    'account_id'   => $item['account_id'],
                    'description'  => $item['description'] ?? null,
                    'amount'       => $item['amount'],
                    'period_start' => $item['period_start'] ?? null,
                    'period_end'   => $item['period_end'] ?? null,
                ]);
            }

            return $budget;
        });
    }

    /** Approve a budget (wrapped in a transaction to prevent concurrent approvals). */
    public function approveBudget(Budget $budget, ?int $userId = null): Budget
    {
        $this->assertBudgetApprovalAuthorized();

        return DB::transaction(function () use ($budget, $userId): Budget {
            $budget = Budget::lockForUpdate()->findOrFail($budget->id);

            if ($budget->status === 'approved') {
                throw InvalidStatusTransitionException::make('Budget', 'approved', 'approved');
            }

            $budget->approve($userId ?? auth()->id());

            return $budget;
        });
    }

    /**
     * Budget approval is a high-level sign-off (accounting.budget.approve — General Manager by
     * default) — separate from accounting.budget.manage, since drafting a budget and approving
     * spend against it shouldn't require the same trust level. Skipped for console callers
     * (seeders, artisan, demo data commands) — there's no web user to check, and CLI access is
     * already a higher trust boundary.
     */
    private function assertBudgetApprovalAuthorized(): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (Gate::forUser(auth()->user())->denies('accounting.budget.approve')) {
            throw new AuthorizationException('Only a General Manager (or equivalent) can approve this budget.');
        }
    }

    /**
     * Get budget vs actual comparison.
     * BudgetItem::$spent is an accessor that runs a query — load it with a subquery
     * sum to avoid N+1 (one query per item).
     */
    public function getBudgetVsActual(Budget $budget): array
    {
        $items = $budget->items()->with('account')->get();

        // Pre-populate _spent_cache for all items in a single query — avoids N+1
        BudgetItem::loadSpentAmounts($items, $budget->period_start, $budget->period_end);

        $comparison = [];
        $totalBudgeted = 0.0;
        $totalActual = 0.0;

        foreach ($items as $item) {
            $actual = (float) $item->spent;
            $budgeted = (float) $item->amount;
            $variance = $budgeted - $actual;
            $percentageUsed = $item->percentage_used;

            $comparison[] = [
                'item'       => $item,
                'account'    => $item->account,
                'budgeted'   => $budgeted,
                'actual'     => $actual,
                'variance'   => $variance,
                'percentage' => $percentageUsed,
                'status'     => $percentageUsed > 100 ? 'over' : ($percentageUsed > 80 ? 'warning' : 'ok'),
            ];

            $totalBudgeted += $budgeted;
            $totalActual += $actual;
        }

        return [
            'budget'         => $budget,
            'items'          => $comparison,
            'total_budgeted' => $totalBudgeted,
            'total_actual'   => $totalActual,
            'total_variance' => $totalBudgeted - $totalActual,
        ];
    }

    /** Get budget summary across all approved budgets in a date range. */
    public function getBudgetSummary(string $startDate, string $endDate): array
    {
        $budgets = Budget::where('status', 'approved')
            ->where('period_start', '<=', $endDate)
            ->where('period_end', '>=', $startDate)
            ->with(['items.account', 'fiscalYear'])
            ->get();

        $summary = [];

        foreach ($budgets as $budget) {
            foreach ($budget->items as $item) {
                $key = $item->account_id;

                if (!isset($summary[$key])) {
                    $summary[$key] = ['account' => $item->account, 'budgeted' => 0.0, 'actual' => 0.0, 'variance' => 0.0];
                }

                $summary[$key]['budgeted'] += (float) $item->amount;
                $summary[$key]['actual'] += (float) $item->spent;
            }
        }

        foreach ($summary as $key => $data) {
            $summary[$key]['variance'] = $data['budgeted'] - $data['actual'];
        }

        return array_values($summary);
    }
}
