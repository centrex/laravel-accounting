<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Http\Resources\AccountResource;
use Centrex\LaravelAccounting\Models\Account;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = Account::query()
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('code', 'like', "%{$request->search}%")
                    ->orWhere('name', 'like', "%{$request->search}%");
            }))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', (bool) $request->active))
            ->with($request->has('with_children') ? 'children' : [])
            ->orderBy('code')
            ->paginate($request->integer('per_page', 50));

        return response()->json(AccountResource::collection($accounts)->response()->getData(true));
    }

    public function show(Account $account): JsonResponse
    {
        $account->load(['parent', 'children']);

        return response()->json(['data' => new AccountResource($account)]);
    }

    public function store(Request $request): JsonResponse
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        $validated = Validator::make($request->all(), [
            'code'        => ["required", 'string', "unique:{$prefix}accounts,code"],
            'name'        => ['required', 'string', 'max:255'],
            'type'        => ['required', 'string', 'in:asset,liability,equity,revenue,expense,other'],
            'subtype'     => ['nullable', 'string'],
            'parent_id'   => ["nullable", "exists:{$prefix}accounts,id"],
            'description' => ['nullable', 'string'],
            'currency'    => ['nullable', 'string', 'size:3'],
            'is_active'   => ['nullable', 'boolean'],
        ])->validate();

        $account = Account::create($validated);

        return response()->json(['data' => new AccountResource($account)], 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $prefix = config('accounting.table_prefix', 'acct_');

        $validated = Validator::make($request->all(), [
            'code'        => ["sometimes", "string", "unique:{$prefix}accounts,code,{$account->id}"],
            'name'        => ['sometimes', 'string', 'max:255'],
            'type'        => ['sometimes', 'string', 'in:asset,liability,equity,revenue,expense,other'],
            'subtype'     => ['nullable', 'string'],
            'parent_id'   => ["nullable", "exists:{$prefix}accounts,id"],
            'description' => ['nullable', 'string'],
            'currency'    => ['nullable', 'string', 'size:3'],
            'is_active'   => ['nullable', 'boolean'],
        ])->validate();

        $account->update($validated);

        return response()->json(['data' => new AccountResource($account)]);
    }

    public function balance(Account $account): JsonResponse
    {
        return response()->json([
            'data' => [
                'account'         => new AccountResource($account),
                'current_balance' => $account->getCurrentBalance(),
                'is_debit_account' => $account->isDebitAccount(),
            ],
        ]);
    }
}
