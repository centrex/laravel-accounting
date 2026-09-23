<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Events\{BillPosted, PaymentRecorded};
use Centrex\Accounting\Exceptions\{DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Bill, JournalEntry, Payment};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Facades\DB;

trait ManagesBills
{
    /** Post a bill: DR Inventory Asset + Tax / CR Accounts Payable. */
    public function postBill(Bill $bill): JournalEntry
    {
        if ($bill->journal_entry_id !== null) {
            throw InvalidStatusTransitionException::make('Bill', $bill->status->value, 'posted');
        }

        $entry = DB::transaction(function () use ($bill): JournalEntry {
            $apAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $expenseAccount = $this->requireAccount($this->accountCode('inventory'));
            $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
            $discountAmount = round((float) ($bill->discount_amount ?? 0), 2);
            $shippingAmount = round((float) ($bill->shipping_amount ?? 0), 2);
            $otherChargesAmount = round((float) ($bill->other_charges_amount ?? 0), 2);
            // Shipping/other charges from the vendor are folded into the inventory cost —
            // bill->total (AP credit) already includes them, so the debit side must too.
            $netExpenseAmount = round((float) $bill->subtotal + $shippingAmount + $otherChargesAmount - $discountAmount, 2);

            // If the goods this bill covers were already capitalized to Inventory via an earlier
            // goods-received posting (e.g. an inventory GRN), don't debit Inventory for that
            // portion again — clear it against the GRNI liability instead. Only the remainder
            // (typically shipping/other charges not known at receipt time, or the full amount if
            // nothing was received yet) hits Inventory here.
            $grniClearAmount = min(max(0.0, (float) ($bill->grni_clearing_amount ?? 0)), $netExpenseAmount);
            $inventoryDebitAmount = round($netExpenseAmount - $grniClearAmount, 2);

            $lines = [];

            if ($inventoryDebitAmount > 0) {
                $lines[] = ['account_id' => $expenseAccount->id, 'type' => 'debit', 'amount' => $inventoryDebitAmount, 'description' => 'Inventory'];
            }

            if ($grniClearAmount > 0) {
                $grniAccount = $this->requireAccount($this->accountCode('goods_received_clearing'));
                $lines[] = ['account_id' => $grniAccount->id, 'type' => 'debit', 'amount' => $grniClearAmount, 'description' => 'Clear goods received not invoiced'];
            }

            $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => $bill->tax_amount, 'description' => 'Tax'];
            $lines[] = ['account_id' => $apAccount->id, 'type' => 'credit', 'amount' => $bill->total, 'description' => 'Accounts Payable'];

            $entry = $this->createJournalEntry([
                'date'          => $bill->bill_date,
                'reference'     => $bill->bill_number,
                'description'   => "Bill {$bill->bill_number} - {$bill->vendor?->name}",
                'currency'      => $bill->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $bill->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveBillSbuCode($bill),
                'lines'         => $lines,
            ]);

            $entry->post();
            $bill->update(['journal_entry_id' => $entry->id, 'status' => 'issued']);

            return $entry;
        });

        BillPosted::dispatch($bill->fresh());

        return $entry;
    }

    /** Record a bill payment: DR Accounts Payable / CR Cash. */
    public function recordBillPayment(Bill $bill, array $paymentData): Payment
    {
        $payment = DB::transaction(function () use ($bill, $paymentData): Payment {
            $bill = Bill::lockForUpdate()->findOrFail($bill->id);

            if (!$bill->is_posted) {
                throw InvalidStatusTransitionException::make('Bill', $bill->status->value, 'payment');
            }

            $amount = (float) $paymentData['amount'];
            // Bill::$balance (total − paid_amount − AP-reducing discounts), not the bare
            // total − paid_amount — see the matching fix in recordInvoicePayment() above.
            $outstanding = round((float) $bill->balance, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
            }

            if (
                Payment::where('payable_type', Bill::class)
                    ->where('payable_id', $bill->id)
                    ->where('amount', $amount)
                    ->tap(fn ($q) => DayRange::onDay($q, 'payment_date', $paymentData['date']))
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forBill($bill->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Bill::class,
                'payable_id'     => $bill->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $apAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? $this->accountCode('cash'));

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Payment for Bill {$bill->bill_number}",
                'currency'    => $bill->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->resolveBillSbuCode($bill),
                'lines'       => [
                    ['account_id' => $apAccount->id,   'type' => 'debit',  'amount' => $amount, 'description' => 'Accounts Payable'],
                    ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash paid'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            $newPaid = round((float) $bill->paid_amount + $amount, 6);
            $newStatus = $newPaid >= (float) $bill->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $bill->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });

        PaymentRecorded::dispatch($payment->fresh());

        return $payment;
    }
}
