<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * Bank Reconciliation Status.
 *
 * Controls whether a reconciliation session can still be edited.
 */
enum BankReconciliationStatus: string
{
    use EnumHelpers;

    /**
     * Draft
     *
     * Statement lines can still be imported, matched, unmatched, or
     * resolved via adjusting journal entries.
     */
    case DRAFT = 'draft';

    /**
     * Completed
     *
     * The GL balance for the account has been reconciled to the statement
     * ending balance. Matched lines are locked against rematching.
     */
    case COMPLETED = 'completed';
}
