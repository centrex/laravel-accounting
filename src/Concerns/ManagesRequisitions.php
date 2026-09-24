<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Enums\{RequisitionStatus, RequisitionType};
use Centrex\Accounting\Exceptions\InvalidStatusTransitionException;
use Centrex\Accounting\Models\{Bill, Expense, Requisition, RequisitionItem};
use Illuminate\Support\Facades\DB;

trait ManagesRequisitions
{
    /**
     * Create a new purchase or expense requisition with line items.
     *
     * @param  array{
     *   type: 'purchase'|'expense',
     *   title: string,
     *   description?: string|null,
     *   vendor_id?: int|null,
     *   account_id?: int|null,
     *   requested_by?: string|null,
     *   requested_date: string,
     *   required_date?: string|null,
     *   currency?: string,
     *   notes?: string|null,
     *   items: array<array{description: string, quantity: float, unit_price: float}>,
     * }  $data
     */
    public function createRequisition(array $data): Requisition
    {
        return DB::transaction(function () use ($data): Requisition {
            $items = $data['items'] ?? [];
            $total = collect($items)->sum(fn ($i) => (float) ($i['quantity'] ?? 1) * (float) ($i['unit_price'] ?? 0));

            $req = Requisition::create([
                'type'           => $data['type'],
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'vendor_id'      => $data['vendor_id'] ?? null,
                'account_id'     => $data['account_id'] ?? null,
                'requested_by'   => $data['requested_by'] ?? null,
                'requested_date' => $data['requested_date'],
                'required_date'  => $data['required_date'] ?? null,
                'total_amount'   => $total,
                'currency'       => strtoupper((string) ($data['currency'] ?? $this->baseCurrency())),
                'notes'          => $data['notes'] ?? null,
                'status'         => RequisitionStatus::DRAFT,
            ]);

            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price'] ?? 0);

                RequisitionItem::create([
                    'requisition_id' => $req->id,
                    'description'    => $item['description'],
                    'quantity'       => $qty,
                    'unit_price'     => $price,
                    'total'          => round($qty * $price, 2),
                ]);
            }

            return $req->fresh('items');
        });
    }

    /** Advance requisition from draft → submitted. */
    public function submitRequisition(Requisition $requisition, ?int $userId = null): Requisition
    {
        return DB::transaction(function () use ($requisition, $userId): Requisition {
            // Locked and status-checked inside the transaction — otherwise two concurrent
            // submits of the same requisition could both pass the check and both submit.
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::DRAFT) {
                throw new InvalidStatusTransitionException(
                    "Cannot submit a requisition with status [{$requisition->status->value}].",
                );
            }

            $requisition->submit($userId ?? auth()->id());

            return $requisition->fresh();
        });
    }

    /** Advance requisition from submitted → approved. */
    public function approveRequisition(Requisition $requisition, ?int $userId = null): Requisition
    {
        return DB::transaction(function () use ($requisition, $userId): Requisition {
            // Locked and status-checked inside the transaction — see submitRequisition().
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::SUBMITTED) {
                throw new InvalidStatusTransitionException(
                    "Cannot approve a requisition with status [{$requisition->status->value}].",
                );
            }

            $requisition->approve($userId ?? auth()->id());

            return $requisition->fresh();
        });
    }

    /** Reject a submitted requisition. */
    public function rejectRequisition(Requisition $requisition, string $reason, ?int $userId = null): Requisition
    {
        return DB::transaction(function () use ($requisition, $reason, $userId): Requisition {
            // Locked and status-checked inside the transaction — see submitRequisition().
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::SUBMITTED) {
                throw new InvalidStatusTransitionException(
                    "Cannot reject a requisition with status [{$requisition->status->value}].",
                );
            }

            $requisition->reject($reason, $userId ?? auth()->id());

            return $requisition->fresh();
        });
    }

    /**
     * Convert an approved purchase requisition into a draft Bill.
     * Items map 1-to-1 as bill line items (qty × unit_price).
     */
    public function convertRequisitionToBill(Requisition $requisition): Bill
    {
        if ($requisition->type !== RequisitionType::PURCHASE) {
            throw new \InvalidArgumentException('convertRequisitionToBill requires a purchase-type requisition.');
        }

        return DB::transaction(function () use ($requisition): Bill {
            // Locked and status-checked inside the transaction — otherwise two concurrent
            // conversions of the same requisition could both pass the check and both
            // create a Bill from it, double-billing the vendor.
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::APPROVED) {
                throw new InvalidStatusTransitionException('Only approved requisitions can be converted.');
            }

            $bill = Bill::create([
                'vendor_id'  => $requisition->vendor_id,
                'bill_date'  => now()->toDateString(),
                'due_date'   => ($requisition->required_date ?? now()->addDays(30))->toDateString(),
                'subtotal'   => $requisition->total_amount,
                'tax_amount' => 0,
                'total'      => $requisition->total_amount,
                'currency'   => $requisition->currency,
                'notes'      => "Converted from requisition {$requisition->requisition_number}",
                'status'     => 'draft',
            ]);

            foreach ($requisition->items as $item) {
                $bill->items()->create([
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'total'       => $item->total,
                    'tax_amount'  => 0,
                ]);
            }

            $requisition->markConverted(Bill::class, $bill->id);

            return $bill->fresh('items');
        });
    }

    /**
     * Convert an approved expense requisition into a draft Expense.
     * Total amount is placed on the requisition's linked account.
     */
    public function convertRequisitionToExpense(Requisition $requisition): Expense
    {
        if ($requisition->type !== RequisitionType::EXPENSE) {
            throw new \InvalidArgumentException('convertRequisitionToExpense requires an expense-type requisition.');
        }

        return DB::transaction(function () use ($requisition): Expense {
            // Locked and status-checked inside the transaction — see the matching comment
            // in convertRequisitionToBill().
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);

            if ($requisition->status !== RequisitionStatus::APPROVED) {
                throw new InvalidStatusTransitionException('Only approved requisitions can be converted.');
            }

            $expense = Expense::create([
                'account_id'     => $requisition->account_id,
                'expense_date'   => now()->toDateString(),
                'subtotal'       => $requisition->total_amount,
                'tax_amount'     => 0,
                'total'          => $requisition->total_amount,
                'currency'       => $requisition->currency,
                'description'    => $requisition->title,
                'notes'          => "Converted from requisition {$requisition->requisition_number}",
                'payment_method' => 'credit',
                'status'         => 'draft',
            ]);

            foreach ($requisition->items as $item) {
                $expense->items()->create([
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'total'       => $item->total,
                ]);
            }

            $requisition->markConverted(Expense::class, $expense->id);

            return $expense->fresh('items');
        });
    }
}
