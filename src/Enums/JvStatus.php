<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

use Centrex\LaravelAccounting\Concerns\EnumHelpers;

/**
 * Journal Voucher Status.
 *
 * Controls the accounting lifecycle of a journal entry.
 *
 * Rules:
 * - Only POSTED journals affect the General Ledger.
 * - VOID journals must not affect balances (or must be reversed).
 * - Status is independent of debit/credit validation.
 */
enum JvStatus: string
{
    use EnumHelpers;

    /**
     * Draft
     *
     * Journal is being prepared.
     * No impact on ledger balances.
     */
    case DRAFT = 'draft';

    /**
     * Posted
     *
     * Journal is finalized and posted to the General Ledger.
     * Affects account balances and financial statements.
     */
    case POSTED = 'posted';

    /**
     * Void
     *
     * Journal has been cancelled.
     *
     * If previously posted, it must be reversed
     * using a separate reversing journal.
     */
    case VOID = 'void';
}
