<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Controllers\Api;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Http\Requests\StoreBankReconciliationRequest;
use Centrex\Accounting\Http\Resources\BankReconciliationResource;
use Centrex\Accounting\Models\{BankReconciliation, BankStatementLine, JournalEntryLine};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly Accounting $accounting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reconciliations = BankReconciliation::query()
            ->with('account')
            ->when($request->account_id, fn ($q) => $q->where('account_id', $request->account_id))
            ->latest('statement_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(BankReconciliationResource::collection($reconciliations)->response()->getData(true));
    }

    public function store(StoreBankReconciliationRequest $request): JsonResponse
    {
        try {
            $reconciliation = $this->accounting->createBankReconciliation($request->validated());

            return response()->json(['data' => new BankReconciliationResource($reconciliation)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(BankReconciliation $bankReconciliation): JsonResponse
    {
        $bankReconciliation->load('statementLines');

        return response()->json(['data' => new BankReconciliationResource($bankReconciliation)]);
    }

    public function importStatementLines(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $request->validate([
            'rows'                       => ['required', 'array', 'min:1'],
            'rows.*.transaction_date'    => ['required', 'date'],
            'rows.*.description'        => ['required', 'string'],
            'rows.*.amount'              => ['required', 'numeric'],
            'rows.*.type'                => ['required', 'in:debit,credit'],
            'rows.*.external_reference'  => ['nullable', 'string'],
        ]);

        try {
            $lines = $this->accounting->importBankStatementLines($bankReconciliation, $request->input('rows'));

            return response()->json(['data' => $lines->values()], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function match(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $request->validate([
            'statement_line_id' => ['required', 'integer'],
            'journal_entry_line_id' => ['required', 'integer'],
        ]);

        try {
            $this->accounting->matchStatementLine(
                BankStatementLine::findOrFail($request->integer('statement_line_id')),
                JournalEntryLine::findOrFail($request->integer('journal_entry_line_id')),
            );

            return response()->json(['message' => 'Matched.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unmatch(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $request->validate(['statement_line_id' => ['required', 'integer']]);

        try {
            $this->accounting->unmatchStatementLine(BankStatementLine::findOrFail($request->integer('statement_line_id')));

            return response()->json(['message' => 'Unmatched.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function complete(BankReconciliation $bankReconciliation): JsonResponse
    {
        try {
            $this->accounting->completeBankReconciliation($bankReconciliation);

            return response()->json(['data' => new BankReconciliationResource($bankReconciliation->fresh())]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
