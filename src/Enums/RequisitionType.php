<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

enum RequisitionType: string
{
    case PURCHASE = 'purchase';
    case EXPENSE  = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase Requisition',
            self::EXPENSE  => 'Expense Requisition',
        };
    }
}
