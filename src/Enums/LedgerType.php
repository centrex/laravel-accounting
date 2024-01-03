<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

/**
 * General ledger account types.
 */
enum LedgerType: string
{
    // Asset: prepaid expenses, cash, accounts receivable, assets, and cash
    // Debit accounts; +ve balance.
    case ASSET   = 'asset';
    case EXPENSE = 'expense';

    // Liability: lines of credit, accounts payable, debt, and notes payable
    // Credit accounts; -ve balance.
    case LIABILITY = 'liability';
    case EQUITY    = 'equity'; // capital
    case INCOME    = 'income'; // revenue
}
