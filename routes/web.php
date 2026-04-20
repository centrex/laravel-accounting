<?php

declare(strict_types = 1);

use Centrex\Accounting\Livewire\{AccountingDashboard, BillDetails, Bills, Budgets, ChartOfAccounts, Customers, Expenses, FinancialReports, InvoiceDetails, Invoices, JournalEntries, Vendors};
use Illuminate\Support\Facades\Route;

Route::middleware(config('accounting.web_middleware', ['web', 'auth']))
    ->prefix(config('accounting.web_prefix', 'accounting'))
    ->name('accounting.')
    ->group(function (): void {
        Route::get('/dashboard', AccountingDashboard::class)->name('dashboard');
        Route::get('/accounts', ChartOfAccounts::class)->name('accounts');
        Route::get('/journal-entries', JournalEntries::class)->name('journal');
        Route::get('/reports', FinancialReports::class)->name('reports');
        Route::get('/invoices', Invoices::class)->name('invoices');
        Route::get('/invoices/{invoice}', InvoiceDetails::class)->name('invoices.show');
        Route::get('/bills', Bills::class)->name('bills');
        Route::get('/bills/{bill}', BillDetails::class)->name('bills.show');
        Route::get('/budgets', Budgets::class)->name('budgets');
        Route::get('/customers', Customers::class)->name('customers');
        Route::get('/vendors', Vendors::class)->name('vendors');
        Route::get('/expenses', Expenses::class)->name('expenses');
    });
