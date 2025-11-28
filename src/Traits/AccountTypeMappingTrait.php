<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Traits;

use Centrex\LaravelAccounting\Enums\{AccountSubtype, AccountType};
use Centrex\LaravelAccounting\Mappers\AccountTypeMapper;

trait AccountTypeMappingTrait
{
    /**
     * Map a subtype to a top-level type.
     */
    public function accountTypeFromSubtype(AccountSubtype|string $subtype): AccountType
    {
        $sub = $subtype instanceof AccountSubtype ? $subtype : AccountSubtype::from($subtype);

        return AccountTypeMapper::fromSubtype($sub);
    }

    /**
     * Get all subtypes belonging to a top-level type.
     *
     * @return AccountSubtype[]
     */
    public function subtypesForType(AccountType|string $type): array
    {
        $t = $type instanceof AccountType ? $type : AccountType::from($type);

        return AccountTypeMapper::toSubtypes($t);
    }

    /**
     * Check if a subtype belongs to a top-level type.
     */
    public function subtypeBelongsToType(AccountSubtype|string $subtype, AccountType|string $type): bool
    {
        $sub = $subtype instanceof AccountSubtype ? $subtype : AccountSubtype::from($subtype);
        $t = $type instanceof AccountType ? $type : AccountType::from($type);

        return in_array($sub, $this->subtypesForType($t), true);
    }

    /**
     * Return subtype string values for a given type (useful for validation rules).
     *
     * @return string[]
     */
    public function subtypeValuesForType(AccountType|string $type): array
    {
        $t = $type instanceof AccountType ? $type : AccountType::from($type);

        return AccountTypeMapper::toSubtypeValues($t);
    }
}
