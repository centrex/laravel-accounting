<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * Lifecycle status for a CreditMemo.
 *
 *   DRAFT → ISSUED → PARTIALLY_REFUNDED → REFUNDED
 *   DRAFT → VOID (only draft memos can be voided — an issued one has already
 *   posted a journal entry, and this package doesn't generate reversing entries)
 */
enum CreditMemoStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';
    case ISSUED = 'issued';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';
    case VOID = 'void';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT              => 'Draft',
            self::ISSUED             => 'Issued',
            self::PARTIALLY_REFUNDED => 'Partially Refunded',
            self::REFUNDED           => 'Refunded',
            self::VOID               => 'Void',
        };
    }
}
