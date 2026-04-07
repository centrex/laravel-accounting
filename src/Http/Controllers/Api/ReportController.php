<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Accounting;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class ReportController extends Controller
{
    public function __construct(private readonly Accounting $accounting) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date'],
        ]);

        try {
            $data = $this->accounting->getTrialBalance(
                $request->start_date,
                $request->end_date ?? now()->toDateString(),
            );

            return response()->json([
                'data' => [
                    'accounts'      => collect($data['accounts'])->map(fn ($row) => [
                        'account' => [
                            'id'   => $row['account']->id,
                            'code' => $row['account']->code,
                            'name' => $row['account']->name,
                            'type' => $row['account']->type,
                        ],
                        'debit'  => $row['debit'],
                        'credit' => $row['credit'],
                    ]),
                    'total_debits'  => $data['total_debits'],
                    'total_credits' => $data['total_credits'],
                    'is_balanced'   => $data['is_balanced'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);

        try {
            $data = $this->accounting->getBalanceSheet($request->date ?? now()->toDateString());

            return response()->json(['data' => $this->formatAccountGroups($data)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $data = $this->accounting->getIncomeStatement($request->start_date, $request->end_date);

            return response()->json(['data' => $this->formatAccountGroups($data)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        try {
            $data = $this->accounting->getCashFlowStatement($request->start_date, $request->end_date);

            return response()->json(['data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /** Serialize account groups (replacing model objects with plain arrays) */
    private function formatAccountGroups(array $data): array
    {
        $formatted = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['accounts'])) {
                $formatted[$key] = [
                    'accounts' => collect($value['accounts'])->map(fn ($row) => [
                        'account' => is_array($row['account'] ?? null) ? $row['account'] : [
                            'id'   => $row['account']->id ?? null,
                            'code' => $row['account']->code ?? null,
                            'name' => $row['account']->name ?? null,
                            'type' => $row['account']->type ?? null,
                        ],
                        'balance' => $row['balance'] ?? ($row['debit'] ?? 0) - ($row['credit'] ?? 0),
                    ])->values(),
                    'total' => $value['total'] ?? 0,
                ] + array_diff_key($value, ['accounts' => null]);
            } else {
                $formatted[$key] = $value;
            }
        }

        return $formatted;
    }
}
