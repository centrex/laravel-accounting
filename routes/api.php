<?php

declare(strict_types = 1);

use Centrex\Accounting\Http\Controllers\Api\{
    AccountController,
    BillController,
    BudgetController,
    CustomerController,
    ExpenseController,
    InvoiceController,
    JournalEntryController,
    ReportController,
    VendorController
};
use Illuminate\Support\Facades\Route;

Route::middleware(config('accounting.api_middleware', ['api', 'auth:sanctum']))
    ->prefix(config('accounting.api_prefix', 'api/accounting'))
    ->name('accounting.api.')
    ->group(function (): void {
        // Chart of Accounts
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::get('accounts/{account}/balance', [AccountController::class, 'balance'])->name('accounts.balance');

        // Journal Entries
        Route::get('journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
        Route::post('journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
        Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->name('journal-entries.show');
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->name('journal-entries.post');
        Route::post('journal-entries/{journalEntry}/void', [JournalEntryController::class, 'void'])->name('journal-entries.void');

        // Invoices
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('invoices/{invoice}/post', [InvoiceController::class, 'post'])->name('invoices.post');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments');
        Route::post('invoices/{invoice}/expenses', [InvoiceController::class, 'recordExpense'])->name('invoices.expenses');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Bills
        Route::get('bills', [BillController::class, 'index'])->name('bills.index');
        Route::post('bills', [BillController::class, 'store'])->name('bills.store');
        Route::get('bills/{bill}', [BillController::class, 'show'])->name('bills.show');
        Route::post('bills/{bill}/post', [BillController::class, 'post'])->name('bills.post');
        Route::post('bills/{bill}/payments', [BillController::class, 'recordPayment'])->name('bills.payments');
        Route::post('bills/{bill}/expenses', [BillController::class, 'recordExpense'])->name('bills.expenses');
        Route::delete('bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

        // Vendors
        Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
        Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
        Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->name('vendors.destroy');

        // Financial Reports
        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
        Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');

        // Expenses
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::post('expenses/{expense}/post', [ExpenseController::class, 'post'])->name('expenses.post');
        Route::post('expenses/{expense}/payments', [ExpenseController::class, 'recordPayment'])->name('expenses.payments');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        // Budgets
        Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
        Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
        Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
        Route::put('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
        Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve'])->name('budgets.approve');
        Route::get('budgets/{budget}/vs-actual', [BudgetController::class, 'vsActual'])->name('budgets.vs-actual');
        Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');
    });
