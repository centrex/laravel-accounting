<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * General Ledger Account Types (IFRS-aligned).
 *
 * Natural balances:
 * - Debit-balance accounts increase on debit
 * - Credit-balance accounts increase on credit
 *
 * Used for:
 * - Chart of Accounts structure
 * - Journal validation
 * - Financial statement classification
 */
enum AccountType: string
{
    use EnumHelpers;

    /**
     * Assets
     *
     * Resources controlled by the entity from which
     * future economic benefits are expected.
     *
     * Natural balance: Debit
     * IFRS Reference: IAS 1
     */
    case ASSET = 'asset';

    /**
     * Expenses
     *
     * Decreases in economic benefits during the accounting period
     * in the form of outflows or depletions of assets.
     *
     * Includes:
     * - Cost of Goods Sold (IAS 2)
     * - Operating Expenses
     * - Finance Costs (IAS 23)
     *
     * Natural balance: Debit
     */
    case EXPENSE = 'expense';

    /**
     * Liabilities
     *
     * Present obligations of the entity arising from past events,
     * the settlement of which is expected to result in an outflow
     * of economic resources.
     *
     * Natural balance: Credit
     * IFRS Reference: IAS 1, IAS 37
     */
    case LIABILITY = 'liability';

    /**
     * Equity
     *
     * Residual interest in the assets of the entity
     * after deducting all liabilities.
     *
     * Includes:
     * - Share capital
     * - Retained earnings
     * - Reserves
     *
     * Natural balance: Credit
     */
    case EQUITY = 'equity';

    /**
     * Revenue (Income)
     *
     * Increases in economic benefits during the accounting period
     * arising from ordinary activities of the entity.
     *
     * Natural balance: Credit
     * IFRS Reference: IFRS 15
     */
    case REVENUE = 'revenue';

    /**
     * Other / Technical Accounts
     *
     * Used only for internal, transitional, or technical purposes.
     *
     * Examples:
     * - Contra accounts
     * - Suspense accounts
     * - Clearing / transit accounts
     *
     * ⚠ Must never appear in published financial statements.
     */
    case OTHER = 'other';
}
