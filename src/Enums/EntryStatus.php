<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * Accounting Entry Lifecycle Status.
 *
 * Represents the business and accounting state of a financial entry
 * (invoice, bill, journal, or payment).
 *
 * NOTE:
 * - Status does NOT affect debit/credit validity.
 * - Posting rules and financial impact are controlled separately.
 */
enum EntryStatus: string
{
    use EnumHelpers;

    /**
     * Draft
     *
     * Entry is being prepared and is not yet finalized.
     * No accounting impact.
     */
    case DRAFT = 'draft';

    /**
     * Sent
     *
     * Entry has been finalized and sent to the counterparty
     * (e.g., invoice sent, bill sent for approval).
     *
     * No accounting impact until issued.
     */
    case SENT = 'sent';

    /**
     * Issued
     *
     * Entry has been finalized and formally issued
     * (e.g., invoice sent, bill received).
     *
     * Accounting impact begins.
     */
    case ISSUED = 'issued';

    /**
     * Partially Settled
     *
     * Entry has received partial payment.
     * Outstanding balance remains.
     */
    case PARTIALLY_SETTLED = 'partially_settled';

    /**
     * Settled
     *
     * Entry has been fully paid or cleared.
     * No outstanding balance remains.
     */
    case SETTLED = 'settled';

    /**
     * Overdue
     *
     * Entry has passed its due date and remains unpaid
     * or partially settled.
     */
    case OVERDUE = 'overdue';

    /**
     * Void
     *
     * Entry has been cancelled and is excluded from
     * financial reporting.
     *
     * Must not be posted or must be reversed if previously posted.
     */
    case VOID = 'void';
}
