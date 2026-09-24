<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Exceptions\{AccountNotFoundException, DuplicatePaymentException, InvalidStatusTransitionException, OverpaymentException};
use Centrex\Accounting\Models\{Account, Bill, Expense, Invoice, JournalEntry, Payment};
use Centrex\Accounting\Support\DayRange;
use Illuminate\Support\Facades\DB;

trait ManagesExpenses
{
    /** Post an inventory-owned expense into the ledger. */
    public function postExpense(Expense $expense): JournalEntry
    {
        return DB::transaction(function () use ($expense): JournalEntry {
            // Locked and status-checked inside the transaction — see the matching comment
            // in InvoiceService::postInvoice(). Otherwise two concurrent posts of the same
            // expense could both pass the check and both create+post a journal entry.
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);

            if (in_array($expense->status, ['paid', 'settled'], true)) {
                throw InvalidStatusTransitionException::make('Expense', $expense->status, 'posted');
            }

            if ($expense->journal_entry_id !== null) {
                throw InvalidStatusTransitionException::make('Expense', (string) $expense->status, 'posted');
            }

            $expenseAccount = $expense->account_id
                ? (Account::find($expense->account_id) ?? throw AccountNotFoundException::forCode('custom'))
                : $this->requireAccount($this->accountCode('cogs'));

            $cashAccount = $this->requireAccount($expense->payment_account_code ?? $this->accountCode('cash'));
            $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $taxAccount = Account::where('code', $this->accountCode('tax_payable'))->where('is_active', true)->first();
            $isCreditExpense = $expense->payment_method === 'credit';

            $lines = [
                ['account_id' => $expenseAccount->id, 'type' => 'debit', 'amount' => (float) $expense->subtotal, 'description' => 'Expense'],
            ];

            if ((float) $expense->tax_amount > 0 && $taxAccount !== null) {
                $lines[] = ['account_id' => $taxAccount->id, 'type' => 'debit', 'amount' => (float) $expense->tax_amount, 'description' => 'Tax'];
            }

            $totalCredit = round((float) $expense->subtotal + (float) $expense->tax_amount, 6);
            $creditAccount = $isCreditExpense ? $payableAccount : $cashAccount;
            $creditDesc = $isCreditExpense ? 'Accounts Payable' : $cashAccount->name;

            $lines[] = ['account_id' => $creditAccount->id, 'type' => 'credit', 'amount' => $totalCredit, 'description' => $creditDesc];

            $entry = $this->createJournalEntry([
                'date'          => $expense->expense_date,
                'reference'     => $expense->expense_number,
                'type'          => 'general',
                'description'   => "Expense {$expense->expense_number}" . ($expense->vendor_name ? " - {$expense->vendor_name}" : ''),
                'currency'      => $expense->currency ?? config('accounting.base_currency', 'BDT'),
                'exchange_rate' => $expense->exchange_rate ?? 1.0,
                'sbu_code'      => $this->resolveExpenseSbuCode($expense),
                'lines'         => $lines,
            ]);

            $entry->post();
            $expense->update([
                'journal_entry_id' => $entry->id,
                'paid_amount'      => $isCreditExpense ? (float) $expense->paid_amount : (float) $expense->total,
                'status'           => $isCreditExpense ? 'approved' : 'paid',
            ]);

            return $entry;
        });
    }

    /**
     * Create and immediately post an expense linked to a specific invoice.
     *
     * Typical use: shipping out, courier fee, COD handling, or any fulfilment cost
     * borne by the company against a customer invoice.
     *
     * Journal entry:
     *   DR [expense_account]  (subtotal)
     *   DR Sales Tax (2300)   (tax_amount, if > 0)
     *   CR Cash (1000)        (payment_method != 'credit')
     *   CR AP   (2000)        (payment_method == 'credit')
     */
    public function recordInvoiceExpense(Invoice $invoice, array $data): Expense
    {
        return $this->recordDocumentExpense(
            $invoice,
            $invoice->invoice_number,
            fn () => $this->resolveInvoiceSbuCode($invoice),
            $data,
            'Invoice expense',
        );
    }

    /**
     * Create and immediately post an expense linked to a specific bill.
     *
     * Typical use: freight-in, customs duty, insurance, or any landed cost
     * associated with a vendor bill / purchase.
     *
     * Journal entry:
     *   DR [expense_account]  (subtotal)
     *   DR Sales Tax (2300)   (tax_amount, if > 0)
     *   CR Cash (1000)        (payment_method != 'credit')
     *   CR AP   (2000)        (payment_method == 'credit')
     */
    public function recordBillExpense(Bill $bill, array $data): Expense
    {
        return $this->recordDocumentExpense(
            $bill,
            $bill->bill_number,
            fn () => $this->resolveBillSbuCode($bill),
            $data,
            'Bill expense',
        );
    }

    /** Shared implementation for recordInvoiceExpense / recordBillExpense. */
    private function recordDocumentExpense(
        Invoice|Bill $document,
        string $documentNumber,
        \Closure $resolveSbu,
        array $data,
        string $defaultDescription,
    ): Expense {
        return DB::transaction(function () use ($document, $documentNumber, $resolveSbu, $data, $defaultDescription): Expense {
            $payload = $this->documentExpensePayload($document, $data, $defaultDescription);

            $expense = Expense::create([
                'chargeable_type' => $document::class,
                'chargeable_id'   => $document->id,
                'account_id'      => $data['account_id'] ?? null,
                'expense_date'    => $payload['date'],
                'subtotal'        => $payload['subtotal'],
                'tax_amount'      => $payload['tax_amount'],
                'total'           => $payload['total'],
                'paid_amount'     => 0,
                'currency'        => $payload['currency'],
                'exchange_rate'   => $payload['exchange_rate'],
                'status'          => 'draft',
                'payment_method'  => $payload['payment_method'],
                'vendor_name'     => $data['vendor_name'] ?? null,
                'reference'       => $data['reference'] ?? $documentNumber,
                'notes'           => $data['notes'] ?? null,
            ]);

            $accounts = $this->documentExpenseAccounts($expense->account_id, $payload['is_cash']);
            $lines = $this->documentExpenseJournalLines($payload, $accounts);

            $entry = $this->createJournalEntry([
                'date'          => $payload['date'],
                'reference'     => $expense->expense_number,
                'type'          => 'general',
                'description'   => "{$payload['description']} — {$documentNumber}",
                'currency'      => $payload['currency'],
                'exchange_rate' => $payload['exchange_rate'],
                'sbu_code'      => $this->normalizeSbuCode($data['sbu_code'] ?? null) ?? $resolveSbu(),
                'lines'         => $lines,
            ]);

            $entry->post();

            $expense->update([
                'journal_entry_id' => $entry->id,
                'paid_amount'      => $payload['is_cash'] ? $payload['total'] : 0,
                'status'           => $payload['is_cash'] ? 'paid' : 'approved',
            ]);

            return $expense;
        });
    }

    private function documentExpensePayload(Invoice|Bill $document, array $data, string $defaultDescription): array
    {
        $subtotal = round((float) ($data['amount'] ?? 0), 2);
        $taxAmount = round((float) ($data['tax_amount'] ?? 0), 2);

        return [
            'subtotal'       => $subtotal,
            'tax_amount'     => $taxAmount,
            'total'          => round($subtotal + $taxAmount, 2),
            'is_cash'        => ($data['payment_method'] ?? 'cash') !== 'credit',
            'payment_method' => $data['payment_method'] ?? 'cash',
            'currency'       => $data['currency'] ?? $document->currency ?? config('accounting.base_currency', 'BDT'),
            'exchange_rate'  => $data['exchange_rate'] ?? 1.0,
            'date'           => $data['date'] ?? $document->{'invoice_date'} ?? $document->{'bill_date'},
            'description'    => $data['description'] ?? $defaultDescription,
        ];
    }

    private function documentExpenseAccounts(?int $expenseAccountId, bool $isCash): array
    {
        $expenseAccount = $expenseAccountId
            ? (Account::find($expenseAccountId) ?? throw AccountNotFoundException::forCode('custom'))
            : $this->requireAccount($this->accountCode('cogs'));
        $cashAccount = $this->requireAccount($this->accountCode('cash'));
        $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));

        return [
            'expense' => $expenseAccount,
            'tax'     => Account::where('code', $this->accountCode('tax_payable'))->where('is_active', true)->first(),
            'credit'  => $isCash ? $cashAccount : $payableAccount,
        ];
    }

    private function documentExpenseJournalLines(array $payload, array $accounts): array
    {
        $lines = [
            ['account_id' => $accounts['expense']->id, 'type' => 'debit', 'amount' => $payload['subtotal'], 'description' => $payload['description']],
        ];

        if ($payload['tax_amount'] > 0 && $accounts['tax'] !== null) {
            $lines[] = ['account_id' => $accounts['tax']->id, 'type' => 'debit', 'amount' => $payload['tax_amount'], 'description' => 'Tax'];
        }

        $lines[] = [
            'account_id'  => $accounts['credit']->id,
            'type'        => 'credit',
            'amount'      => $payload['total'],
            'description' => $payload['is_cash'] ? 'Cash paid' : 'Accounts Payable',
        ];

        return $lines;
    }

    /** Record settlement of a credit expense: DR AP / CR Cash. */
    public function recordExpensePayment(Expense $expense, array $paymentData): Payment
    {
        return DB::transaction(function () use ($expense, $paymentData): Payment {
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);

            $amount = (float) $paymentData['amount'];
            $outstanding = round((float) $expense->total - (float) $expense->paid_amount, 6);

            if ($amount > $outstanding + $this->tolerance()) {
                throw OverpaymentException::make($amount, $outstanding);
            }

            if (
                Payment::where('payable_type', Expense::class)
                    ->where('payable_id', $expense->id)
                    ->where('amount', $amount)
                    ->tap(fn ($q) => DayRange::onDay($q, 'payment_date', $paymentData['date']))
                    ->where('payment_method', $paymentData['method'])
                    ->exists()
            ) {
                throw DuplicatePaymentException::forExpense($expense->id, $amount, (string) $paymentData['date']);
            }

            $payment = Payment::create([
                'payment_number' => $paymentData['payment_number'] ?? ('PMT-' . now()->format('YmdHis') . '-' . random_int(1000, 9999)),
                'payable_type'   => Expense::class,
                'payable_id'     => $expense->id,
                'payment_date'   => $paymentData['date'],
                'amount'         => $amount,
                'payment_method' => $paymentData['method'],
                'reference'      => $paymentData['reference'] ?? null,
                'notes'          => $paymentData['notes'] ?? null,
            ]);

            $payableAccount = $this->requireAccount($this->accountCode('accounts_payable'));
            $cashAccount = $this->requireAccount($paymentData['account_code'] ?? $this->accountCode('cash'));

            $entry = $this->createJournalEntry([
                'date'        => $paymentData['date'],
                'reference'   => $payment->payment_number,
                'description' => "Expense payment {$expense->expense_number}",
                'currency'    => $expense->currency ?? config('accounting.base_currency', 'BDT'),
                'sbu_code'    => $this->resolveExpenseSbuCode($expense, $paymentData),
                'lines'       => [
                    ['account_id' => $payableAccount->id, 'type' => 'debit',  'amount' => $amount, 'description' => 'Expense payable'],
                    ['account_id' => $cashAccount->id,    'type' => 'credit', 'amount' => $amount, 'description' => 'Cash paid'],
                ],
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            $newPaid = round((float) $expense->paid_amount + $amount, 6);
            $newStatus = $newPaid >= (float) $expense->total - $this->tolerance() ? 'paid' : 'partial';

            $expense->update(['paid_amount' => $newPaid, 'status' => $newStatus]);

            return $payment;
        });
    }
}
