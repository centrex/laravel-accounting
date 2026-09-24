<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Services;

use Centrex\Accounting\Concerns\{HasSharedAccountingHelpers, ManagesJournalEntries};
use Centrex\Accounting\Enums\EntryStatus;
use Centrex\Accounting\Events\{InvoicePosted, PaymentRecorded};
use Centrex\Accounting\Exceptions\{DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Expense, Invoice, JournalEntry, Payment};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    use HasSharedAccountingHelpers;
    use ManagesJournalEntries;

    /** Post an invoice: create & post a journal entry, update invoice status to 'issued'. */
    public function postInvoice(Invoice $invoice): JournalEntry
    {
        $entry = DB::transaction(function () use ($invoice): JournalEntry {
            // Locked and status-checked inside the transaction — the checks used to run
            // against an $invoice loaded before the transaction opened, so two concurrent
            // posts of the same invoice could both pass and both create+post a journal
            // entry, double-recognizing revenue.
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status === EntryStatus::SETTLED) {
                throw InvalidStatusTransitionException::make('Invoice', 'settled', 'posted');
            }

            if ($invoice->journal_entry_id !== null) {
                throw InvalidStatusTransitionException::make('Invoice', $invoice->status->value, 'posted');
            }

            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));
            $revenueAccount = $this->requireAccount($this->accountCode('sales_revenue'));
            $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
            $discountAmount = round((float) ($invoice->discount_amount ?? 0), 2);
            $shippingAmount = round((float) ($invoice->shipping_amount ?? 0), 2);
            // Shipping billed to the customer is folded into revenue — invoice->total
            // (AR debit) already includes it, so revenue must too for the entry to balance.
            $netRevenueAmount = round((float) $invoice->subtotal + $shippingAmount - $discountAmount, 2);

            $entry = $this->createJournalEntry([
                'date'          => $invoice->invoice_date,
                'reference'     => $invoice->invoice_number,
                'type'          => 'general',
                'description'   => "Invoice {$invoice->invoice_number} - {$invoice->customer?->name}",
                'currency'      => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $invoice->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveInvoiceSbuCode($invoice),
                'lines'         => [
                    ['account_id' => $arAccount->id,      'type' => 'debit',  'amount' => $invoice->total,      'description' => 'Accounts Receivable'],
                    ['account_id' => $revenueAccount->id, 'type' => 'credit', 'amount' => $netRevenueAmount, 'description' => $discountAmount > 0 ? 'Sales Revenue (net of discount)' : 'Sales Revenue'],
                    ['account_id' => $taxAccount->id,     'type' => 'credit', 'amount' => $invoice->tax_amount, 'description' => 'Sales Tax'],
                ],
            ]);

            $entry->post();

            $invoice->update(['journal_entry_id' => $entry->id, 'status' => 'issued']);

            return $entry;
        });

        InvoicePosted::dispatch($invoice->fresh());

        return $entry;
    }

    /**
     * Record a payment against an invoice.
     *
     * Uses a pessimistic row-lock to prevent concurrent payment races.
     * Validates overpayment and idempotency before writing anything.
     */
    public function recordInvoicePayment(Invoice $invoice, array $paymentData): Payment
    {
        $payment = DB::transaction(function () use ($invoice, $paymentData): Payment {
            // Pessimistic lock — blocks concurrent payments for the same invoice
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (!$invoice->is_posted) {
                throw InvalidStatusTransitionException::make('Invoice', $invoice->status->value, 'payment');
            }

            $amount = (float) $paymentData['amount'];
            // Optional shipping/handling charge netted off AR alongside the cash received —
            // reduces the customer's balance without an extra cash leg.
            $chargeAmount = (float) ($paymentData['charge_amount'] ?? 0);
            $arReduction = round($amount + $chargeAmount, 6);
            // Invoice::$balance (total − paid_amount − AR-reducing discounts − issued credit
            // memos), not the bare total − paid_amount: a discount or a sale-return credit memo
            // already reduces what's actually collectible, and comparing against the wider
            // total-paid figure let a payment be accepted for more than that true remaining
            // balance — driving paid_amount past what the invoice can support and corrupting
            // the resynced SaleOrder::due_amount (see ErpIntegration::resyncSaleOrderDueAmount()).
            $outstanding = round((float) $invoice->balance, 6);

            if ($arReduction > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($arReduction, $outstanding);
            }

            // Idempotency guard — same amount + date + method = duplicate
            if (
                Payment::where('payable_type', Invoice::class)
                    ->where('payable_id', $invoice->id)
                    ->where('amount', $amount)
                    ->tap(fn ($q) => DayRange::onDay($q, 'payment_date', $paymentData['date']))
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forInvoice($invoice->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Invoice::class,
                'payable_id'     => $invoice->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            // Resolve cash account — caller may specify a custom account code (e.g. bank vs cash)
            $cashCode = $paymentData['account_code'] ?? $this->accountCode('cash');
            $cashAccount = $this->requireAccount($cashCode);
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $lines = [
                ['account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => $amount, 'description' => 'Cash received'],
            ];

            $chargeAccount = null;

            if ($chargeAmount > 0) {
                $chargeAccount = $this->requireAccount((string) ($paymentData['charge_account_code'] ?? $this->accountCode('shipping')));
                $lines[] = ['account_id' => $chargeAccount->id, 'type' => 'debit', 'amount' => $chargeAmount, 'description' => 'Shipping/handling charge netted off AR'];
            }

            $lines[] = ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => $arReduction, 'description' => 'Accounts Receivable'];

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
                'currency'    => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->resolveInvoiceSbuCode($invoice),
                'lines'       => $lines,
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            if ($chargeAccount !== null) {
                // No journal_entry_id here — this charge is already booked as a line inside
                // $entry above. Linking the same entry a second time would make it show up
                // twice in views that merge invoice->payments and invoice->expenses journal
                // entries (e.g. the invoice audit trail), making one payment look like two.
                Expense::create([
                    'chargeable_type' => Invoice::class,
                    'chargeable_id'   => $invoice->id,
                    'account_id'      => $chargeAccount->id,
                    'expense_date'    => $paymentData['date'],
                    'subtotal'        => $chargeAmount,
                    'tax_amount'      => 0,
                    'total'           => $chargeAmount,
                    'paid_amount'     => $chargeAmount,
                    'currency'        => $invoice->currency ?? config('accounting.base_currency', 'BDT'),
                    'status'          => 'paid',
                    'payment_method'  => 'ar_deduction',
                    'reference'       => $payment->payment_number,
                    'notes'           => 'Netted off AR during payment ' . $payment->payment_number,
                ]);
            }

            // Atomic status update — compute from known locked value, no refresh needed
            $newPaid = round((float) $invoice->paid_amount + $arReduction, 6);
            $newStatus = $newPaid >= (float) $invoice->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $invoice->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });

        PaymentRecorded::dispatch($payment->fresh());

        return $payment;
    }
}
