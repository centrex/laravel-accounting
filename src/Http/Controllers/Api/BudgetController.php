<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Http\Resources\{BudgetResource};
use Centrex\LaravelAccounting\Models\{Budget, BudgetItem};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function __construct(private readonly Accounting $accounting) {}

    public function index(Request $request): JsonResponse
    {
        $budgets = Budget::query()
            ->with(['fiscalYear', 'items.account'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->fiscal_year_id, fn ($q) => $q->where('fiscal_year_id', $request->fiscal_year_id))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('budget_number', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            }))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json(BudgetResource::collection($budgets)->response()->getData(true));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                 => 'required|string|max:255',
            'fiscal_year_id'       => 'nullable|integer|exists:acct_fiscal_years,id',
            'period_start'         => 'required|date',
            'period_end'           => 'required|date|after_or_equal:period_start',
            'total_amount'         => 'required|numeric|min:0',
            'currency'             => 'nullable|string|size:3',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.account_id'   => 'required|integer|exists:acct_accounts,id',
            'items.*.description'  => 'nullable|string|max:255',
            'items.*.amount'       => 'required|numeric|min:0',
            'items.*.period_start' => 'nullable|date',
            'items.*.period_end'   => 'nullable|date',
        ]);

        $totalAllocated = collect($validated['items'])->sum('amount');

        if (abs((float) $validated['total_amount'] - $totalAllocated) > 0.01) {
            return response()->json(['message' => 'Total amount must equal sum of item amounts.'], 422);
        }

        $budget = DB::transaction(function () use ($validated): Budget {
            $budget = Budget::create([
                'name'           => $validated['name'],
                'fiscal_year_id' => $validated['fiscal_year_id'] ?? null,
                'period_start'   => $validated['period_start'],
                'period_end'     => $validated['period_end'],
                'total_amount'   => $validated['total_amount'],
                'currency'       => $validated['currency'] ?? config('accounting.base_currency', 'BDT'),
                'status'         => 'draft',
                'notes'          => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                BudgetItem::create([
                    'budget_id'    => $budget->id,
                    'account_id'   => $item['account_id'],
                    'description'  => $item['description'] ?? null,
                    'amount'       => $item['amount'],
                    'period_start' => $item['period_start'] ?? $validated['period_start'],
                    'period_end'   => $item['period_end'] ?? $validated['period_end'],
                ]);
            }

            return $budget;
        });

        return response()->json(['data' => new BudgetResource($budget->load(['fiscalYear', 'items.account']))], 201);
    }

    public function show(Budget $budget): JsonResponse
    {
        $budget->load(['fiscalYear', 'items.account']);

        return response()->json(['data' => new BudgetResource($budget)]);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        if ($budget->status === 'approved') {
            return response()->json(['message' => 'Cannot modify an approved budget.'], 422);
        }

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'fiscal_year_id' => 'nullable|integer|exists:acct_fiscal_years,id',
            'period_start'   => 'sometimes|date',
            'period_end'     => 'sometimes|date|after_or_equal:period_start',
            'total_amount'   => 'sometimes|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $budget->update($validated);

        return response()->json(['data' => new BudgetResource($budget->load(['fiscalYear', 'items.account']))]);
    }

    public function approve(Request $request, Budget $budget): JsonResponse
    {
        try {
            $this->accounting->approveBudget($budget, $request->user()?->id);

            return response()->json(['data' => new BudgetResource($budget->fresh(['fiscalYear', 'items.account']))]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function vsActual(Budget $budget): JsonResponse
    {
        $comparison = $this->accounting->getBudgetVsActual($budget);

        return response()->json(['data' => $comparison]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        if ($budget->status === 'approved') {
            return response()->json(['message' => 'Cannot delete an approved budget.'], 422);
        }

        $budget->delete();

        return response()->json(null, 204);
    }
}
