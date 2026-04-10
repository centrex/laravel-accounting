<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Http\Controllers\Api;

use Centrex\Accounting\Http\Requests\StoreCustomerRequest;
use Centrex\Accounting\Http\Resources\CustomerResource;
use Centrex\Accounting\Models\Customer;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', (bool) $request->active))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json(CustomerResource::collection($customers)->response()->getData(true));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return response()->json(['data' => new CustomerResource($customer)], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function update(StoreCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->invoices()->whereIn('status', ['issued', 'partially_settled', 'overdue'])->exists()) {
            return response()->json(['message' => 'Cannot delete customer with outstanding invoices'], 422);
        }

        $customer->delete();

        return response()->json(null, 204);
    }
}
