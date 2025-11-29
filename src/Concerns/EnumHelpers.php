<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Concerns;

use Illuminate\Support\Str;

trait EnumHelpers
{
    public function is(self $other): bool
    {
        return $this === $other;
    }

    public function isNot(self $other): bool
    {
        return !$this->is($other);
    }

    public function getName(): string
    {
        return __(Str::replace('_', ' ', $this->name));
    }

    public function getValue()
    {
        return $this->value;
    }

    public static function getLabel($value): ?string
    {
        foreach (self::cases() as $case) {
            if ($case->getValue() === $value) {
                return $case->getName();
            }
        }

        return null;
    }
}
