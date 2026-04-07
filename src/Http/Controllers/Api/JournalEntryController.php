<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Http\Requests\StoreJournalEntryRequest;
use Centrex\LaravelAccounting\Http\Resources\JournalEntryResource;
use Centrex\LaravelAccounting\Models\JournalEntry;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class JournalEntryController extends Controller
{
    public function __construct(private readonly Accounting $accounting) {}

    public function index(Request $request): JsonResponse
    {
        $entries = JournalEntry::query()
            ->with(['lines.account', 'creator'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn ($q) => $q->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('date', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('entry_number', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%")
                    ->orWhere('reference', 'like', "%{$request->search}%");
            }))
            ->latest('date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(JournalEntryResource::collection($entries)->response()->getData(true));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        try {
            $entry = $this->accounting->createJournalEntry($request->validated());

            return response()->json(['data' => new JournalEntryResource($entry->load('lines.account'))], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry->load(['lines.account', 'creator', 'approver']);

        return response()->json(['data' => new JournalEntryResource($journalEntry)]);
    }

    public function post(JournalEntry $journalEntry): JsonResponse
    {
        try {
            $journalEntry->post();

            return response()->json(['data' => new JournalEntryResource($journalEntry->fresh('lines.account'))]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function void(JournalEntry $journalEntry): JsonResponse
    {
        try {
            $journalEntry->void();

            return response()->json(['data' => new JournalEntryResource($journalEntry->fresh('lines.account'))]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
