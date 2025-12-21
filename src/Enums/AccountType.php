<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

use Centrex\LaravelAccounting\Concerns\EnumHelpers;

/**
 * High-level general ledger account categories.
 *
 * Natural balances:
 * - Asset & Expense accounts → Debit (increase)
 * - Liability, Equity & Income accounts → Credit (increase)
 */
enum AccountType: string
{
    use EnumHelpers;

    // Debit-balance accounts
    case ASSET = 'asset';
    case EXPENSE = 'expense';

    // Credit-balance accounts
    case LIABILITY = 'liability';
    case EQUITY = 'equity';   // also called Capital / Net Worth
    case REVENUE = 'revenue';

    // Optional fallback for non-standard accounts
    case OTHER = 'other';
}
