<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Controllers\Api;

use Centrex\Accounting\Http\Requests\StoreTaxRateRequest;
use Centrex\Accounting\Http\Resources\TaxRateResource;
use Centrex\Accounting\Models\TaxRate;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class TaxRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $taxRates = TaxRate::query()
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            }))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', (bool) $request->active))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json(TaxRateResource::collection($taxRates)->response()->getData(true));
    }

    public function store(StoreTaxRateRequest $request): JsonResponse
    {
        $taxRate = TaxRate::create($request->validated());

        return response()->json(['data' => new TaxRateResource($taxRate)], 201);
    }

    public function show(TaxRate $taxRate): JsonResponse
    {
        return response()->json(['data' => new TaxRateResource($taxRate)]);
    }

    public function update(StoreTaxRateRequest $request, TaxRate $taxRate): JsonResponse
    {
        $taxRate->update($request->validated());

        return response()->json(['data' => new TaxRateResource($taxRate)]);
    }

    public function destroy(TaxRate $taxRate): JsonResponse
    {
        if ($taxRate->invoiceItems()->exists() || $taxRate->billItems()->exists()) {
            return response()->json(['message' => 'Cannot delete a tax rate that has been used on invoices or bills; deactivate it instead.'], 422);
        }

        $taxRate->delete();

        return response()->json(null, 204);
    }
}
