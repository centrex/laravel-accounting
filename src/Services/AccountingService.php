<?php

namespace Centrex\LaravelAccounting\Services;

use Centrex\LaravelAccounting\Models\Account;
use Centrex\LaravelAccounting\Models\JournalEntry;
use Centrex\LaravelAccounting\Models\JournalEntryLine;
use Centrex\LaravelAccounting\Models\FiscalYear;
use Centrex\LaravelAccounting\Models\Invoice;
use Centrex\LaravelAccounting\Models\Bill;
use Centrex\LaravelAccounting\Models\Payment;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Create a journal entry with lines
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {
            $entry = JournalEntry::create([
                'date' => $data['date'],
                'reference' => $data['reference'] ?? null,
                'type' => $data['type'] ?? 'general',
                'description' => $data['description'],
                'currency' => $data['currency'] ?? 'USD',
                'created_by' => auth()->id(),
                'status' => 'draft'
            ]);

            foreach ($data['lines'] as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'type' => $line['type'], // debit or credit
                    'amount' => $line['amount'],
                    'description' => $line['description'] ?? null,
                    'reference' => $line['reference'] ?? null,
                ]);
            }

            if (!$entry->isBalanced()) {
                throw new \Exception('Journal entry is not balanced. Debits must equal credits.');
            }

            return $entry;
        });
    }

    /**
     * Post an invoice and create journal entry
     */
    public function postInvoice(Invoice $invoice): JournalEntry
    {
        if ($invoice->status === 'paid') {
            throw new \Exception('Invoice is already paid');
        }

        return DB::transaction(function () use ($invoice) {
            // Get accounts
            $arAccount = Account::where('code', '1200')->first(); // Accounts Receivable
            $revenueAccount = Account::where('code', '4000')->first(); // Sales Revenue
            $taxAccount = Account::where('code', '2300')->first(); // Sales Tax Payable

            $entry = $this->createJournalEntry([
                'date' => $invoice->invoice_date,
                'reference' => $invoice->invoice_number,
                'type' => 'general',
                'description' => "Invoice {$invoice->invoice_number} - {$invoice->customer->name}",
                'lines' => [
                    [
                        'account_id' => $arAccount->id,
                        'type' => 'debit',
                        'amount' => $invoice->total,
                        'description' => 'Accounts Receivable',
                    ],
                    [
                        'account_id' => $revenueAccount->id,
                        'type' => 'credit',
                        'amount' => $invoice->subtotal,
                        'description' => 'Sales Revenue',
                    ],
                    [
                        'account_id' => $taxAccount->id,
                        'type' => 'credit',
                        'amount' => $invoice->tax_amount,
                        'description' => 'Sales Tax',
                    ]
                ]
            ]);

            $entry->post();
            
            $invoice->update([
                'journal_entry_id' => $entry->id,
                'status' => 'sent'
            ]);

            return $entry;
        });
    }

    /**
     * Record invoice payment
     */
    public function recordInvoicePayment(Invoice $invoice, array $paymentData): Payment
    {
        return DB::transaction(function () use ($invoice, $paymentData) {
            $payment = Payment::create([
                'payable_type' => Invoice::class,
                'payable_id' => $invoice->id,
                'payment_date' => $paymentData['date'],
                'amount' => $paymentData['amount'],
                'payment_method' => $paymentData['method'],
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? null,
            ]);

            // Create journal entry for payment
            $cashAccount = Account::where('code', '1000')->first(); // Cash
            $arAccount = Account::where('code', '1200')->first(); // AR

            $entry = $this->createJournalEntry([
                'date' => $paymentData['date'],
                'reference' => $payment->payment_number,
                'description' => "Payment received for Invoice {$invoice->invoice_number}",
                'lines' => [
                    [
                        'account_id' => $cashAccount->id,
                        'type' => 'debit',
                        'amount' => $paymentData['amount'],
                        'description' => 'Cash received',
                    ],
                    [
                        'account_id' => $arAccount->id,
                        'type' => 'credit',
                        'amount' => $paymentData['amount'],
                        'description' => 'Accounts Receivable',
                    ]
                ]
            ]);

            $entry->post();
            $payment->update(['journal_entry_id' => $entry->id]);

            // Update invoice
            $invoice->increment('paid_amount', $paymentData['amount']);
            
            if ($invoice->paid_amount >= $invoice->total) {
                $invoice->update(['status' => 'paid']);
            } else {
                $invoice->update(['status' => 'partial']);
            }

            return $payment;
        });
    }

    /**
     * Post a bill (vendor invoice)
     */
    public function postBill(Bill $bill): JournalEntry
    {
        return DB::transaction(function () use ($bill) {
            $apAccount = Account::where('code', '2000')->first(); // Accounts Payable
            $expenseAccount = Account::where('code', '5000')->first(); // Expenses
            $taxAccount = Account::where('code', '2300')->first(); // Tax Payable

            $entry = $this->createJournalEntry([
                'date' => $bill->bill_date,
                'reference' => $bill->bill_number,
                'description' => "Bill {$bill->bill_number} - {$bill->vendor->name}",
                'lines' => [
                    [
                        'account_id' => $expenseAccount->id,
                        'type' => 'debit',
                        'amount' => $bill->subtotal,
                        'description' => 'Expense',
                    ],
                    [
                        'account_id' => $taxAccount->id,
                        'type' => 'debit',
                        'amount' => $bill->tax_amount,
                        'description' => 'Tax',
                    ],
                    [
                        'account_id' => $apAccount->id,
                        'type' => 'credit',
                        'amount' => $bill->total,
                        'description' => 'Accounts Payable',
                    ]
                ]
            ]);

            $entry->post();
            
            $bill->update([
                'journal_entry_id' => $entry->id,
                'status' => 'approved'
            ]);

            return $entry;
        });
    }

    /**
     * Generate Trial Balance
     */
    public function getTrialBalance($startDate = null, $endDate = null): array
    {
        $accounts = Account::where('is_active', true)
            ->orderBy('code')
            ->get();

        $trialBalance = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $query = $account->journalEntryLines()
                ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted');
                    
                    if ($startDate) {
                        $q->whereDate('date', '>=', $startDate);
                    }
                    if ($endDate) {
                        $q->whereDate('date', '<=', $endDate);
                    }
                });

            $debits = $query->clone()->where('type', 'debit')->sum('amount');
            $credits = $query->clone()->where('type', 'credit')->sum('amount');

            $balance = $account->isDebitAccount() 
                ? ($debits - $credits) 
                : ($credits - $debits);

            if ($balance != 0) {
                $trialBalance[] = [
                    'account' => $account,
                    'debit' => $balance > 0 ? abs($balance) : 0,
                    'credit' => $balance < 0 ? abs($balance) : 0,
                ];

                $totalDebits += $balance > 0 ? abs($balance) : 0;
                $totalCredits += $balance < 0 ? abs($balance) : 0;
            }
        }

        return [
            'accounts' => $trialBalance,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'is_balanced' => abs($totalDebits - $totalCredits) < 0.01
        ];
    }

    /**
     * Generate Balance Sheet
     */
    public function getBalanceSheet($date = null): array
    {
        $date = $date ?? now();

        $assets = $this->getAccountsByType('asset', $date);
        $liabilities = $this->getAccountsByType('liability', $date);
        $equity = $this->getAccountsByType('equity', $date);

        // Calculate retained earnings (net income - withdrawals)
        $netIncome = $this->getNetIncome(null, $date);
        $retainedEarnings = $equity['total'] + $netIncome;

        return [
            'date' => $date,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => array_merge($equity, [
                'net_income' => $netIncome,
                'retained_earnings' => $retainedEarnings,
                'total_with_income' => $liabilities['total'] + $retainedEarnings
            ]),
            'is_balanced' => abs($assets['total'] - ($liabilities['total'] + $retainedEarnings)) < 0.01
        ];
    }

    /**
     * Generate Income Statement (P&L)
     */
    public function getIncomeStatement($startDate, $endDate): array
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate);

        $grossProfit = $revenue['total'];
        $netIncome = $revenue['total'] - $expenses['total'];

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'gross_profit' => $grossProfit,
            'net_income' => $netIncome,
        ];
    }

    /**
     * Generate Cash Flow Statement
     */
    public function getCashFlowStatement($startDate, $endDate): array
    {
        $cashAccount = Account::where('code', '1000')->first();
        
        if (!$cashAccount) {
            throw new \Exception('Cash account not found');
        }

        $transactions = $cashAccount->journalEntryLines()
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted')
                  ->whereBetween('date', [$startDate, $endDate]);
            })
            ->with(['journalEntry', 'account'])
            ->get();

        $operating = 0;
        $investing = 0;
        $financing = 0;

        foreach ($transactions as $transaction) {
            $amount = $transaction->type === 'debit' 
                ? $transaction->amount 
                : -$transaction->amount;

            // Categorize based on related account types
            $relatedLines = $transaction->journalEntry->lines()
                ->where('id', '!=', $transaction->id)
                ->with('account')
                ->get();

            foreach ($relatedLines as $line) {
                if (in_array($line->account->type, ['revenue', 'expense'])) {
                    $operating += $amount;
                } elseif ($line->account->subtype === 'fixed_asset') {
                    $investing += $amount;
                } elseif (in_array($line->account->type, ['liability', 'equity'])) {
                    $financing += $amount;
                }
            }
        }

        $netChange = $operating + $investing + $financing;

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'operating_activities' => $operating,
            'investing_activities' => $investing,
            'financing_activities' => $financing,
            'net_change' => $netChange,
        ];
    }

    /**
     * Helper: Get accounts by type with balances
     */
    protected function getAccountsByType($type, $endDate, $startDate = null): array
    {
        $accounts = Account::where('type', $type)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $accountsData = [];
        $total = 0;

        foreach ($accounts as $account) {
            $query = $account->journalEntryLines()
                ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted');
                    
                    if ($startDate) {
                        $q->whereDate('date', '>=', $startDate);
                    }
                    if ($endDate) {
                        $q->whereDate('date', '<=', $endDate);
                    }
                });

            $debits = $query->clone()->where('type', 'debit')->sum('amount');
            $credits = $query->clone()->where('type', 'credit')->sum('amount');

            $balance = $account->isDebitAccount() 
                ? ($debits - $credits) 
                : ($credits - $debits);

            if (abs($balance) > 0.01) {
                $accountsData[] = [
                    'account' => $account,
                    'balance' => $balance
                ];
                $total += $balance;
            }
        }

        return [
            'accounts' => $accountsData,
            'total' => $total
        ];
    }

    /**
     * Helper: Get net income
     */
    protected function getNetIncome($startDate, $endDate): float
    {
        $revenue = $this->getAccountsByType('revenue', $endDate, $startDate);
        $expenses = $this->getAccountsByType('expense', $endDate, $startDate);
        
        return $revenue['total'] - $expenses['total'];
    }

    /**
     * Initialize Chart of Accounts with standard accounts
     */
    public function initializeChartOfAccounts(): void
    {
        $accounts = [
            // Assets
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1100', 'name' => 'Bank Account', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1500', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1700', 'name' => 'Fixed Assets', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            ['code' => '1800', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            
            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2100', 'name' => 'Credit Card Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2200', 'name' => 'Accrued Expenses', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Debt', 'type' => 'liability', 'subtype' => 'long_term_liability'],
            
            // Equity
            ['code' => '3000', 'name' => 'Owner\'s Equity', 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '3200', 'name' => 'Owner\'s Draw', 'type' => 'equity', 'subtype' => 'equity'],
            
            // Revenue
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue'],
            ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue'],
            ['code' => '4900', 'name' => 'Other Income', 'type' => 'revenue', 'subtype' => 'non_operating_revenue'],
            
            // Expenses
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '6000', 'name' => 'Salaries & Wages', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6100', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6200', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6300', 'name' => 'Office Supplies', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6400', 'name' => 'Insurance', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6500', 'name' => 'Marketing & Advertising', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6600', 'name' => 'Depreciation', 'type' => 'expense', 'subtype' => 'operating_expense'],
            ['code' => '6700', 'name' => 'Interest Expense', 'type' => 'expense', 'subtype' => 'non_operating_expense'],
            ['code' => '6800', 'name' => 'Bank Fees', 'type' => 'expense', 'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['code' => $accountData['code']],
                array_merge($accountData, ['is_system' => true])
            );
        }
    }

    /**
     * Close fiscal year (transfer net income to retained earnings)
     */
    public function closeFiscalYear(FiscalYear $fiscalYear): void
    {
        if ($fiscalYear->is_closed) {
            throw new \Exception('Fiscal year is already closed');
        }

        DB::transaction(function () use ($fiscalYear) {
            $netIncome = $this->getNetIncome(
                $fiscalYear->start_date,
                $fiscalYear->end_date
            );

            // Close income and expense accounts to retained earnings
            $retainedEarnings = Account::where('code', '3100')->first();
            $incomeSummary = Account::where('code', '3900')->first();

            if (!$incomeSummary) {
                $incomeSummary = Account::create([
                    'code' => '3900',
                    'name' => 'Income Summary',
                    'type' => 'equity',
                    'subtype' => 'equity',
                    'is_system' => true
                ]);
            }

            // Transfer net income to retained earnings
            if ($netIncome != 0) {
                $entry = $this->createJournalEntry([
                    'date' => $fiscalYear->end_date,
                    'reference' => 'YE-' . $fiscalYear->name,
                    'type' => 'closing',
                    'description' => "Closing entry for fiscal year {$fiscalYear->name}",
                    'lines' => [
                        [
                            'account_id' => $incomeSummary->id,
                            'type' => $netIncome > 0 ? 'debit' : 'credit',
                            'amount' => abs($netIncome),
                            'description' => 'Income Summary',
                        ],
                        [
                            'account_id' => $retainedEarnings->id,
                            'type' => $netIncome > 0 ? 'credit' : 'debit',
                            'amount' => abs($netIncome),
                            'description' => 'Retained Earnings',
                        ]
                    ]
                ]);

                $entry->post();
            }

            $fiscalYear->update(['is_closed' => true]);
        });
    }
}