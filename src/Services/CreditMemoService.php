<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Services;

use Centrex\Accounting\Concerns\{HasSharedAccountingHelpers, ManagesJournalEntries};
use Centrex\Accounting\Enums\CreditMemoStatus;
use Centrex\Accounting\Exceptions\{AccountingException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{CreditMemo, Invoice, JournalEntry, Payment};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Facades\DB;

class CreditMemoService
{
    use HasSharedAccountingHelpers;
    use ManagesJournalEntries;

    /**
     * Create a draft credit memo against an invoice (e.g. for a sale return).
     * No accounting impact until issueCreditMemo() is called.
     *
     * @param array{
     *     date?: mixed, reason?: string, subtotal?: float|int|string, tax_amount?: float|int|string,
     *     total?: float|int|string, source_type?: string, source_id?: int, source_reference?: string,
     *     sbu_code?: string, notes?: string, created_by?: int
     * } $data
     */
    public function createCreditMemo(Invoice $invoice, array $data): CreditMemo
    {
        if ($invoice->journal_entry_id === null) {
            throw new AccountingException("Invoice {$invoice->invoice_number} must be posted before a credit memo can be raised against it.");
        }

        $subtotal = round((float) ($data['subtotal'] ?? 0), 2);
        $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);
        $total = round((float) ($data['total'] ?? $subtotal + $taxAmount), 2);

        if ($total <= 0) {
            throw new AccountingException('Credit memo total must be greater than zero.');
        }

        return CreditMemo::create([
            'invoice_id'       => $invoice->id,
            'customer_id'      => $invoice->customer_id,
            'credit_memo_date' => $data['date'] ?? now()->toDateString(),
            'reason'           => $data['reason'] ?? null,
            'currency'         => $invoice->currency ?? $this->baseCurrency(),
            'exchange_rate'    => $invoice->exchange_rate ?? 1.0,
            'subtotal'         => $subtotal,
            'tax_amount'       => $taxAmount,
            'total'            => $total,
            'status'           => CreditMemoStatus::DRAFT->value,
            'source_type'      => $data['source_type'] ?? null,
            'source_id'        => $data['source_id'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'sbu_code'         => $this->normalizeSbuCode($data['sbu_code'] ?? null) ?? $this->resolveInvoiceSbuCode($invoice),
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Issue a draft credit memo: posts the reversing journal entry
     * DR Sales Returns (6134) + DR Tax Payable / CR Accounts Receivable.
     *
     * Guard: across all issued memos and manual discounts, an invoice can never be
     * credited for more than it was invoiced (payments are irrelevant here — a fully
     * paid invoice can still be credited, driving its balance negative, which is what
     * flags a cash refund as owed).
     */
    public function issueCreditMemo(CreditMemo $creditMemo): JournalEntry
    {
        return DB::transaction(function () use ($creditMemo): JournalEntry {
            $creditMemo = CreditMemo::lockForUpdate()->findOrFail($creditMemo->id);

            if ($creditMemo->status !== CreditMemoStatus::DRAFT) {
                throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'issued');
            }

            $invoice = Invoice::lockForUpdate()->findOrFail($creditMemo->invoice_id);

            $alreadyCredited = (float) $invoice->creditMemos()
                ->whereNotIn('status', [CreditMemoStatus::DRAFT->value, CreditMemoStatus::VOID->value])
                ->sum('total');
            $discounts = (float) $invoice->expenses()
                ->whereHas('account', fn ($q) => $q->whereIn('code', Invoice::AR_REDUCING_ACCOUNT_CODES))
                ->sum('total');
            $creditable = round((float) $invoice->total - $alreadyCredited - $discounts, 2);

            if ((float) $creditMemo->total > $creditable + $this->tolerance()) {
                throw new AccountingException(sprintf(
                    'Credit memo total %.2f exceeds the creditable balance %.2f of invoice %s.',
                    (float) $creditMemo->total,
                    $creditable,
                    $invoice->invoice_number,
                ));
            }

            $salesReturnsAccount = $this->requireAccount($this->accountCode('sales_returns'));
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $lines = [
                ['account_id' => $salesReturnsAccount->id, 'type' => 'debit', 'amount' => $creditMemo->subtotal, 'description' => 'Sales Returns & Allowances'],
            ];

            if ((float) $creditMemo->tax_amount > 0) {
                $taxAccount = $this->requireAccount($this->accountCode('tax_payable'));
                $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => $creditMemo->tax_amount, 'description' => 'Sales Tax reversal'];
            }

            $lines[] = ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => $creditMemo->total, 'description' => 'Accounts Receivable'];

            $entry = $this->createJournalEntry([
                'date'          => $creditMemo->credit_memo_date,
                'reference'     => $creditMemo->credit_memo_number,
                'type'          => 'general',
                'description'   => "Credit memo {$creditMemo->credit_memo_number} against Invoice {$invoice->invoice_number}",
                'currency'      => $creditMemo->currency ?? $this->baseCurrency(),
                'exchange_rate' => $creditMemo->exchange_rate ?? 1.0,
                'sbu_code'      => $this->normalizeSbuCode($creditMemo->sbu_code) ?? $this->resolveInvoiceSbuCode($invoice),
                'source_type'   => CreditMemo::class,
                'source_id'     => $creditMemo->id,
                'source_action' => 'credit_memo_issued',
                'lines'         => $lines,
            ]);

            $entry->post();

            $creditMemo->update([
                'journal_entry_id' => $entry->id,
                'status'           => CreditMemoStatus::ISSUED->value,
                'issued_by'        => auth()->id(),
                'issued_at'        => now(),
            ]);

            return $entry;
        });
    }

    /**
     * Refund an issued credit memo in cash: DR Accounts Receivable / CR Cash (or bank).
     *
     * The issue step already credited AR, so the refund debits it back while cash goes
     * out — netting the customer's AR effect of the memo to zero. Recorded as a Payment
     * row (like invoice payments) for the audit trail.
     *
     * @param  array{date: mixed, amount: float|int|string, method: string, account_code?: string, reference?: string, notes?: string, sbu_code?: string}  $paymentData
     */
    public function recordCreditMemoRefund(CreditMemo $creditMemo, array $paymentData): Payment
    {
        return DB::transaction(function () use ($creditMemo, $paymentData): Payment {
            $creditMemo = CreditMemo::lockForUpdate()->findOrFail($creditMemo->id);

            if (!in_array($creditMemo->status, [CreditMemoStatus::ISSUED, CreditMemoStatus::PARTIALLY_REFUNDED], true)) {
                throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'refunded');
            }

            $amount = round((float) $paymentData['amount'], 2);
            // Capped by cash actually received on the invoice — see CreditMemo::getRefundableAmountAttribute().
            $refundable = $creditMemo->refundable_amount;

            if ($amount <= 0) {
                throw new AccountingException('Refund amount must be greater than zero.');
            }

            if ($amount > $refundable + $this->tolerance()) {
                throw OverpaymentException::make($amount, $refundable);
            }

            // Idempotency guard — same amount + date + method = duplicate
            if (
                Payment::where('payable_type', CreditMemo::class)
                    ->where('payable_id', $creditMemo->id)
                    ->where('amount', $amount)
                    ->tap(fn ($q) => DayRange::onDay($q, 'payment_date', $paymentData['date']))
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw new AccountingException("A refund of {$amount} on {$paymentData['date']} already exists for credit memo {$creditMemo->credit_memo_number}.");
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('RFND-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => CreditMemo::class,
                'payable_id'     => $creditMemo->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $cashCode = $paymentData['account_code'] ?? $this->accountCode('cash');
            $cashAccount = $this->requireAccount((string) $cashCode);
            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $entry = $this->createJournalEntry([
                'date'          => $paymentData['date'],
                'reference'     => $payment->payment_number,
                'description'   => "Refund for Credit memo {$creditMemo->credit_memo_number}",
                'currency'      => $creditMemo->currency ?? $this->baseCurrency(),
                'sbu_code'      => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->normalizeSbuCode($creditMemo->sbu_code),
                'source_type'   => CreditMemo::class,
                'source_id'     => $creditMemo->id,
                'source_action' => 'credit_memo_refunded',
                'lines'         => [
                    ['account_id' => $arAccount->id,   'type' => 'debit',  'amount' => $amount, 'description' => 'Accounts Receivable'],
                    ['account_id' => $cashAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => 'Cash refunded to customer'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            // Atomic status update — compute from known locked value, no refresh needed
            $newRefunded = round((float) $creditMemo->amount_refunded + $amount, 2);
            $newStatus = $newRefunded >= (float) $creditMemo->total - $this->tolerance()
                ? CreditMemoStatus::REFUNDED->value
                : CreditMemoStatus::PARTIALLY_REFUNDED->value;

            $creditMemo->update(['amount_refunded' => $newRefunded, 'status' => $newStatus]);

            return $payment;
        });
    }

    /**
     * Apply an issued credit memo's refundable balance toward a *different* invoice
     * instead of paying it back in cash.
     *
     * Economically this is a cash refund immediately re-collected as payment on
     * $targetInvoice, so the cash leg washes out: DR Accounts Receivable (releasing the
     * memo's invoice) / CR Accounts Receivable (settling the target invoice) — both lines
     * hit the same GL account, netting to zero there.
     *
     * Two Payment rows are written for the one JE — payable_type=CreditMemo (like
     * recordCreditMemoRefund()) and payable_type=Invoice (like recordInvoicePayment()) —
     * so both documents' own payment history shows the event, and so CustomerLedger's
     * `refunds` term (Payment against the CreditMemo) correctly offsets `memoCredits`
     * (the memo's full total) by the amount already consumed here. Without the
     * CreditMemo-side row, that ledger would keep counting this memo's whole total as
     * still-outstanding credit even after part of it was spent settling $targetInvoice.
     *
     * Still capped by refundable_amount: applying a memo raised against an unpaid invoice
     * has nothing real to move — the return already reduced that invoice's own balance.
     *
     * @param  array{date: mixed, amount: float|int|string, reference?: string, notes?: string, sbu_code?: string}  $paymentData
     */
    public function applyCreditMemoToInvoice(CreditMemo $creditMemo, Invoice $targetInvoice, array $paymentData): Payment
    {
        return DB::transaction(function () use ($creditMemo, $targetInvoice, $paymentData): Payment {
            $creditMemo = CreditMemo::lockForUpdate()->findOrFail($creditMemo->id);

            if (!in_array($creditMemo->status, [CreditMemoStatus::ISSUED, CreditMemoStatus::PARTIALLY_REFUNDED], true)) {
                throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'refunded');
            }

            $targetInvoice = Invoice::lockForUpdate()->findOrFail($targetInvoice->id);

            if ($targetInvoice->id === $creditMemo->invoice_id) {
                throw new AccountingException('Cannot apply a credit memo to the invoice it was issued against — that invoice\'s balance already reflects the credit.');
            }

            if ($targetInvoice->customer_id !== $creditMemo->customer_id) {
                throw new AccountingException('The credit memo and the target invoice belong to different customers.');
            }

            if (!$targetInvoice->is_posted) {
                throw InvalidStatusTransitionException::make('Invoice', $targetInvoice->status->value, 'payment');
            }

            $amount = round((float) $paymentData['amount'], 2);
            // Capped by cash actually received on the memo's own invoice — see CreditMemo::getRefundableAmountAttribute().
            $refundable = $creditMemo->refundable_amount;

            if ($amount <= 0) {
                throw new AccountingException('Applied amount must be greater than zero.');
            }

            if ($amount > $refundable + $this->tolerance()) {
                throw OverpaymentException::make($amount, $refundable);
            }

            $outstanding = round((float) $targetInvoice->balance, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
            }

            // Idempotency guard — same amount + date + memo reference = duplicate
            if (
                Payment::where('payable_type', Invoice::class)
                    ->where('payable_id', $targetInvoice->id)
                    ->where('amount', $amount)
                    ->tap(fn ($q) => DayRange::onDay($q, 'payment_date', $paymentData['date']))
                    ->where('payment_method', 'credit_memo')
                    ->where('reference', $creditMemo->credit_memo_number)
                    ->exists()
            ) {
                throw new AccountingException("A credit memo application of {$amount} on {$paymentData['date']} already exists for invoice {$targetInvoice->invoice_number}.");
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Invoice::class,
                'payable_id'     => $targetInvoice->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => 'credit_memo',
                'reference'      => $paymentData['reference'] ?? $creditMemo->credit_memo_number,
                'notes'          => $paymentData['notes'] ?? "Applied from credit memo {$creditMemo->credit_memo_number}",
            ]);

            // Mirrors recordCreditMemoRefund()'s Payment shape so CustomerLedger's `refunds`
            // term sees this amount as consumed from the memo — see the docblock above.
            $memoPayment = Payment::create([
                'payment_number' => 'RFND-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                'payable_type'   => CreditMemo::class,
                'payable_id'     => $creditMemo->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => 'credit_memo',
                'reference'      => $targetInvoice->invoice_number,
                'notes'          => "Applied to Invoice {$targetInvoice->invoice_number}",
            ]);

            $arAccount = $this->requireAccount($this->accountCode('accounts_receivable'));

            $entry = $this->createJournalEntry([
                'date'          => $paymentData['date'],
                'reference'     => $payment->payment_number,
                'description'   => "Credit memo {$creditMemo->credit_memo_number} applied to Invoice {$targetInvoice->invoice_number}",
                'currency'      => $targetInvoice->currency ?? $this->baseCurrency(),
                'sbu_code'      => $this->normalizeSbuCode($paymentData['sbu_code'] ?? null) ?? $this->normalizeSbuCode($creditMemo->sbu_code) ?? $this->resolveInvoiceSbuCode($targetInvoice),
                'source_type'   => CreditMemo::class,
                'source_id'     => $creditMemo->id,
                'source_action' => 'credit_memo_applied',
                'lines'         => [
                    ['account_id' => $arAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => "Release credit memo {$creditMemo->credit_memo_number}"],
                    ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => $amount, 'description' => "Settle Invoice {$targetInvoice->invoice_number}"],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);
            $memoPayment->update(['journal_entry_id' => $entry->id]);

            // Atomic status updates — compute from known locked values, no refresh needed
            $newRefunded = round((float) $creditMemo->amount_refunded + $amount, 2);
            $newMemoStatus = $newRefunded >= (float) $creditMemo->total - $this->tolerance()
                ? CreditMemoStatus::REFUNDED->value
                : CreditMemoStatus::PARTIALLY_REFUNDED->value;

            $creditMemo->update(['amount_refunded' => $newRefunded, 'status' => $newMemoStatus]);

            $newPaid = round((float) $targetInvoice->paid_amount + $amount, 6);
            $newInvoiceStatus = $newPaid >= (float) $targetInvoice->total - $this->tolerance() ? 'settled' : 'partially_settled';

            $targetInvoice->update(['paid_amount' => $newPaid, 'status' => $newInvoiceStatus]);

            return $payment;
        });
    }

    /** Void a draft credit memo. Issued memos cannot be voided — their JE has already posted. */
    public function voidCreditMemo(CreditMemo $creditMemo): CreditMemo
    {
        if ($creditMemo->status !== CreditMemoStatus::DRAFT) {
            throw InvalidStatusTransitionException::make('CreditMemo', $creditMemo->status->value, 'void');
        }

        $creditMemo->update(['status' => CreditMemoStatus::VOID->value]);

        return $creditMemo;
    }
}
