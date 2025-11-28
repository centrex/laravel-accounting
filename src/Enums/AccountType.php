<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

/**
 * High-level general ledger account categories.
 *
 * Natural balances:
 * - Asset & Expense accounts → Debit (increase)
 * - Liability, Equity & Income accounts → Credit (increase)
 */
enum AccountType: string
{
    // Debit-balance accounts
    case ASSET = 'asset';
    case EXPENSE = 'expense';

    // Credit-balance accounts
    case LIABILITY = 'liability';
    case EQUITY = 'equity';   // also called Capital / Net Worth
    case INCOME = 'income';   // same as Revenue

    // Optional fallback for non-standard accounts
    case OTHER = 'other';
}
