<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Http\Requests\{RecordPaymentRequest, StoreBillRequest};
use Centrex\LaravelAccounting\Http\Resources\{BillResource, PaymentResource};
use Centrex\LaravelAccounting\Models\{Bill, BillItem, Payment};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    public function __construct(private readonly Accounting $accounting) {}

    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->with(['vendor'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->vendor_id, fn ($q) => $q->where('vendor_id', $request->vendor_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('bill_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('bill_date', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('bill_number', 'like', "%{$request->search}%")
                    ->orWhereHas('vendor', fn ($q) => $q->where('name', 'like', "%{$request->search}%"));
            }))
            ->latest('bill_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(BillResource::collection($bills)->response()->getData(true));
    }

    public function store(StoreBillRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bill = DB::transaction(function () use ($data): Bill {
            $currency = $data['currency'] ?? config('accounting.base_currency', 'BDT');
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($data['items'] as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $itemTax = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal += $amount;
                $taxAmount += $itemTax;
            }

            $bill = Bill::create([
                'vendor_id'  => $data['vendor_id'],
                'bill_date'  => $data['bill_date'],
                'due_date'   => $data['due_date'],
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $subtotal + $taxAmount,
                'currency'   => $currency,
                'status'     => 'draft',
                'notes'      => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax = $amount * (($item['tax_rate'] ?? 0) / 100);

                BillItem::create([
                    'bill_id'     => $bill->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }

            return $bill;
        });

        return response()->json(['data' => new BillResource($bill->load(['vendor', 'items']))], 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->load(['vendor', 'items', 'payments', 'journalEntry']);

        return response()->json(['data' => new BillResource($bill)]);
    }

    public function post(Bill $bill): JsonResponse
    {
        try {
            $entry = $this->accounting->postBill($bill);

            return response()->json([
                'data'             => new BillResource($bill->fresh(['vendor', 'items'])),
                'journal_entry_id' => $entry->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function recordPayment(RecordPaymentRequest $request, Bill $bill): JsonResponse
    {
        try {
            $payment = DB::transaction(function () use ($request, $bill): Payment {
                $payment = Payment::create([
                    'payable_type'   => Bill::class,
                    'payable_id'     => $bill->id,
                    'payment_date'   => $request->date,
                    'amount'         => $request->amount,
                    'payment_method' => $request->method,
                    'reference'      => $request->reference,
                    'notes'          => $request->notes,
                ]);

                $bill->increment('paid_amount', $request->amount);
                $bill->refresh();

                $status = (float) $bill->paid_amount >= (float) $bill->total ? 'paid' : 'partial';
                $bill->update(['status' => $status]);

                return $payment;
            });

            return response()->json(['data' => new PaymentResource($payment)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Bill $bill): JsonResponse
    {
        if ($bill->status->value !== 'draft') {
            return response()->json(['message' => 'Only draft bills can be deleted'], 422);
        }

        $bill->delete();

        return response()->json(null, 204);
    }
}
