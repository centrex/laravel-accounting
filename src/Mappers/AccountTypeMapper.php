<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Mappers;

use Centrex\LaravelAccounting\Enums\{AccountSubtype, AccountType};

final class AccountTypeMapper
{
    /**
     * Map a detailed AccountSubtype to its parent AccountType.
     */
    public static function fromSubtype(AccountSubtype $subtype): AccountType
    {
        return match ($subtype) {
            // ASSETS
            AccountSubtype::CASH,
            AccountSubtype::CURRENT_ASSET,
            AccountSubtype::FIXED_ASSET,
            AccountSubtype::ACCOUNTS_RECEIVABLE,
            AccountSubtype::PREPAID_EXPENSES => AccountType::ASSET,

            // LIABILITIES
            AccountSubtype::ACCOUNTS_PAYABLE,
            AccountSubtype::CURRENT_LIABILITY,
            AccountSubtype::LONG_TERM_LIABILITY,
            AccountSubtype::SHORT_TERM_LIABILITY => AccountType::LIABILITY,

            // EQUITY
            AccountSubtype::EQUITY => AccountType::EQUITY,

            // INCOME / REVENUE
            AccountSubtype::REVENUE,
            AccountSubtype::OPERATING_REVENUE,
            AccountSubtype::NON_OPERATING_REVENUE => AccountType::INCOME,

            // EXPENSES
            AccountSubtype::EXPENSE,
            AccountSubtype::OPERATING_EXPENSE,
            AccountSubtype::NON_OPERATING_EXPENSE,
            AccountSubtype::COST_OF_GOODS_SOLD => AccountType::EXPENSE,

            // FALLBACK
            default => AccountType::OTHER,
        };
    }

    /**
     * Reverse mapping: return the list of AccountSubtype values that belong to a top-level AccountType.
     *
     * @return AccountSubtype[] Array of AccountSubtype enum cases
     */
    public static function toSubtypes(AccountType $type): array
    {
        return match ($type) {
            AccountType::ASSET => [
                AccountSubtype::CASH,
                AccountSubtype::CURRENT_ASSET,
                AccountSubtype::FIXED_ASSET,
                AccountSubtype::ACCOUNTS_RECEIVABLE,
                AccountSubtype::PREPAID_EXPENSES,
            ],

            AccountType::LIABILITY => [
                AccountSubtype::ACCOUNTS_PAYABLE,
                AccountSubtype::CURRENT_LIABILITY,
                AccountSubtype::LONG_TERM_LIABILITY,
                AccountSubtype::SHORT_TERM_LIABILITY,
            ],

            AccountType::EQUITY => [
                AccountSubtype::EQUITY,
            ],

            AccountType::INCOME => [
                AccountSubtype::REVENUE,
                AccountSubtype::OPERATING_REVENUE,
                AccountSubtype::NON_OPERATING_REVENUE,
            ],

            AccountType::EXPENSE => [
                AccountSubtype::EXPENSE,
                AccountSubtype::OPERATING_EXPENSE,
                AccountSubtype::NON_OPERATING_EXPENSE,
                AccountSubtype::COST_OF_GOODS_SOLD,
            ],

            // OTHER -> return empty array to indicate no standard subtypes
            default => [],
        };
    }

    /**
     * Convenience: return subtype values as strings (useful for validation rules).
     *
     * @return string[]
     */
    public static function toSubtypeValues(AccountType $type): array
    {
        return array_map(fn (AccountSubtype $s) => $s->value, self::toSubtypes($type));
    }
}
