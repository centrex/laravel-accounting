<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Enums;

use Centrex\LaravelAccounting\Concerns\EnumHelpers;

enum JvStatus: string
{
    use EnumHelpers;

    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOID = 'void'; 
}