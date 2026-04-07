<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Http\Controllers\Api;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Http\Requests\{RecordPaymentRequest, StoreInvoiceRequest};
use Centrex\LaravelAccounting\Http\Resources\{InvoiceResource, PaymentResource};
use Centrex\LaravelAccounting\Models\{Invoice, InvoiceItem};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function __construct(private readonly Accounting $accounting) {}

    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->with(['customer'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->customer_id, fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('invoice_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('invoice_date', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request): void {
                $q->where('invoice_number', 'like', "%{$request->search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$request->search}%"));
            }))
            ->latest('invoice_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json(InvoiceResource::collection($invoices)->response()->getData(true));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data): Invoice {
            $currency = $data['currency'] ?? config('accounting.base_currency', 'BDT');
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($data['items'] as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $itemTax = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal += $amount;
                $taxAmount += $itemTax;
            }

            $invoice = Invoice::create([
                'customer_id'     => $data['customer_id'],
                'invoice_date'    => $data['invoice_date'],
                'due_date'        => $data['due_date'],
                'subtotal'        => $subtotal,
                'tax_amount'      => $taxAmount,
                'discount_amount' => 0,
                'total'           => $subtotal + $taxAmount,
                'currency'        => $currency,
                'status'          => 'draft',
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax = $amount * (($item['tax_rate'] ?? 0) / 100);

                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }

            return $invoice;
        });

        return response()->json(['data' => new InvoiceResource($invoice->load(['customer', 'items']))], 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['customer', 'items', 'payments', 'journalEntry']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status->value !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be updated'], 422);
        }

        $invoice->update($request->only(['due_date', 'notes', 'currency']));

        return response()->json(['data' => new InvoiceResource($invoice->load(['customer', 'items']))]);
    }

    public function post(Invoice $invoice): JsonResponse
    {
        try {
            $entry = $this->accounting->postInvoice($invoice);

            return response()->json([
                'data'             => new InvoiceResource($invoice->fresh(['customer', 'items'])),
                'journal_entry_id' => $entry->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function recordPayment(RecordPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        try {
            $payment = $this->accounting->recordInvoicePayment($invoice, $request->validated());

            return response()->json(['data' => new PaymentResource($payment)], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->status->value !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted'], 422);
        }

        $invoice->delete();

        return response()->json(null, 204);
    }
}
