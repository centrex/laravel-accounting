<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Http\Requests\StoreVendorRequest;
use Centrex\LaravelAccounting\Http\Resources\VendorResource;
use Centrex\LaravelAccounting\Models\Vendor;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vendors = Vendor::query()
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', (bool) $request->active))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json(VendorResource::collection($vendors)->response()->getData(true));
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = Vendor::create($request->validated());

        return response()->json(['data' => new VendorResource($vendor)], 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json(['data' => new VendorResource($vendor)]);
    }

    public function update(StoreVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return response()->json(['data' => new VendorResource($vendor)]);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        if ($vendor->bills()->whereIn('status', ['approved', 'partial'])->exists()) {
            return response()->json(['message' => 'Cannot delete vendor with outstanding bills'], 422);
        }

        $vendor->delete();

        return response()->json(null, 204);
    }
}
