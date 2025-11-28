<?php

declare(strict_types = 1);

use Centrex\LaravelAccounting\Livewire\{AccountingDashboard, ChartOfAccounts, JournalEntries, FinancialReports};
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('accounting')->group(function () {
    Route::get('/dashboard', AccountingDashboard::class)->name('accounting.dashboard');
    Route::get('/accounts', ChartOfAccounts::class)->name('accounting.accounts');
    Route::get('/journal-entries', JournalEntries::class)->name('accounting.journal');
    Route::get('/reports', FinancialReports::class)->name('accounting.reports');
});