<?php

declare(strict_types = 1);

use Centrex\Accounting\Livewire\{AccountingDashboard, BankReconciliationDetails, BankReconciliations, BillDetails, Bills, Budgets, ChartOfAccounts, CreditMemoDetails, CreditMemos, CustomerLedger, CustomerLedgerIndex, Customers, ExpenseDetails, Expenses, FinancialReports, FixedAssets, GeneralLedger, InvoiceDetails, Invoices, JournalEntries, LoanFacilities, OwnerEquity, PeriodClose, Requisitions, TaxRates, VendorLedger, VendorLedgerIndex, Vendors};
use Illuminate\Support\Facades\Route;

Route::middleware(config('accounting.web_middleware', ['web', 'auth']))
    ->prefix(config('accounting.web_prefix', 'accounting'))
    ->name('accounting.')
    ->group(function (): void {
        Route::get('/dashboard', AccountingDashboard::class)->name('dashboard');
        Route::get('/accounts', ChartOfAccounts::class)->name('accounts');
        Route::get('/journal-entries', JournalEntries::class)->name('journal');
        Route::get('/ledger', GeneralLedger::class)->name('ledger');
        Route::get('/ledger/customers', CustomerLedgerIndex::class)->name('ledger.customers');
        Route::get('/ledger/vendors', VendorLedgerIndex::class)->name('ledger.vendors');
        Route::get('/reports', FinancialReports::class)->name('reports');
        Route::get('/invoices', Invoices::class)->name('invoices');
        Route::get('/invoices/{invoice}', InvoiceDetails::class)->name('invoices.show');
        Route::get('/credit-memos', CreditMemos::class)->name('credit-memos');
        Route::get('/credit-memos/{creditMemo}', CreditMemoDetails::class)->name('credit-memos.show');
        Route::get('/bills', Bills::class)->name('bills');
        Route::get('/bills/{bill}', BillDetails::class)->name('bills.show');
        Route::get('/budgets', Budgets::class)->name('budgets');
        Route::get('/loans', LoanFacilities::class)->name('loans');
        Route::get('/equity', OwnerEquity::class)->name('equity');
        Route::get('/fixed-assets', FixedAssets::class)->name('fixed-assets');
        Route::get('/customers', Customers::class)->name('customers');
        Route::get('/customers/{customer}/ledger', CustomerLedger::class)->name('customers.ledger');
        Route::get('/vendors', Vendors::class)->name('vendors');
        Route::get('/vendors/{vendor}/ledger', VendorLedger::class)->name('vendors.ledger');
        Route::get('/expenses', Expenses::class)->name('expenses');
        Route::get('/expenses/{expense}', ExpenseDetails::class)->name('expenses.show');
        Route::get('/period-close', PeriodClose::class)->name('period-close');
        Route::get('/requisitions', Requisitions::class)->name('requisitions');
        Route::get('/tax-rates', TaxRates::class)->name('tax-rates');
        Route::get('/bank-reconciliations', BankReconciliations::class)->name('bank-reconciliations');
        Route::get('/bank-reconciliations/{bankReconciliation}', BankReconciliationDetails::class)->name('bank-reconciliations.show');
    });
