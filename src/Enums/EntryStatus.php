<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

use Centrex\LaravelAccounting\Concerns\EnumHelpers;

enum EntryStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';
    case SENT = 'sent';
    case PAID = 'paid';
    case PARTIAL = 'partial';
    case OVERDUE = 'overdue';
    case VOID = 'void'; 
}