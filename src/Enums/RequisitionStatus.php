<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

enum RequisitionStatus: string
{
    case DRAFT     = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED  = 'approved';
    case REJECTED  = 'rejected';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted',
            self::APPROVED  => 'Approved',
            self::REJECTED  => 'Rejected',
            self::CONVERTED => 'Converted',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT     => in_array($target, [self::SUBMITTED], true),
            self::SUBMITTED => in_array($target, [self::APPROVED, self::REJECTED, self::DRAFT], true),
            self::APPROVED  => in_array($target, [self::CONVERTED], true),
            default         => false,
        };
    }
}
