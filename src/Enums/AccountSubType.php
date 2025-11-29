<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

use Centrex\LaravelAccounting\Concerns\EnumHelpers;

enum AccountSubtype: string
{
    use EnumHelpers;

    // Cash
    case CASH = 'cash';

    // Assets
    case CURRENT_ASSET = 'current_asset';
    case FIXED_ASSET = 'fixed_asset';
    case ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    case PREPAID_EXPENSES = 'prepaid_expenses';

    // Liabilities
    case CURRENT_LIABILITY = 'current_liability';
    case LONG_TERM_LIABILITY = 'long_term_liability';
    case SHORT_TERM_LIABILITY = 'short_term_liability';
    case ACCOUNTS_PAYABLE = 'accounts_payable';

    // Equity
    case EQUITY = 'equity';

    // Revenue
    case REVENUE = 'revenue';
    case OPERATING_REVENUE = 'operating_revenue';
    case NON_OPERATING_REVENUE = 'non_operating_revenue';

    // Expense
    case EXPENSE = 'expense';
    case OPERATING_EXPENSE = 'operating_expense';
    case NON_OPERATING_EXPENSE = 'non_operating_expense';
    case COST_OF_GOODS_SOLD = 'cost_of_goods_sold';
}
