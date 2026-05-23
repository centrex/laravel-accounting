<?php

declare(strict_types = 1);

namespace Centrex\Accounting\QuickBooks;

use Centrex\Accounting\Enums\{AccountSubtype, AccountType};

/**
 * Maps laravel-accounting AccountType/AccountSubtype to QuickBooks Online (QBO)
 * AccountType and AccountSubType strings used by the QBO v3 REST API.
 *
 * QBO AccountType reference:
 * https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/account
 */
final class QuickBooksAccountTypeMapper
{
    /**
     * Primary mapping: our AccountSubtype value → [qboAccountType, qboAccountSubType]
     *
     * QBO AccountType values (exactly as the API expects):
     *   Bank | Accounts Receivable | Other Current Asset | Fixed Asset | Other Asset |
     *   Accounts Payable | Credit Card | Other Current Liability | Long Term Liability |
     *   Equity | Income | Other Income | Cost of Goods Sold | Expense | Other Expense
     */
    private const SUBTYPE_MAP = [
        // ----- BANK -----
        AccountSubtype::CASH->value                  => ['AccountType' => 'Bank',                    'AccountSubType' => 'CashOnHand'],
        AccountSubtype::PETTY_CASH_ACCOUNT->value    => ['AccountType' => 'Bank',                    'AccountSubType' => 'CashOnHand'],
        AccountSubtype::CHECKING_ACCOUNT->value      => ['AccountType' => 'Bank',                    'AccountSubType' => 'Checking'],
        AccountSubtype::SAVINGS_ACCOUNT->value       => ['AccountType' => 'Bank',                    'AccountSubType' => 'Savings'],
        AccountSubtype::MONEY_MARKET_ACCOUNT->value  => ['AccountType' => 'Bank',                    'AccountSubType' => 'MoneyMarket'],
        AccountSubtype::ESCROW_ACCOUNT->value        => ['AccountType' => 'Bank',                    'AccountSubType' => 'TrustAccounts'],
        AccountSubtype::SUSPENSE_CASH_ACCOUNT->value => ['AccountType' => 'Bank',                    'AccountSubType' => 'Checking'],

        // ----- ACCOUNTS RECEIVABLE -----
        AccountSubtype::ACCOUNTS_RECEIVABLE->value   => ['AccountType' => 'Accounts Receivable',     'AccountSubType' => 'AccountsReceivable'],

        // ----- OTHER CURRENT ASSET -----
        AccountSubtype::CURRENT_ASSET->value         => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountSubtype::PREPAID_EXPENSES->value      => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'PrepaidExpenses'],
        AccountSubtype::INVESTMENT_ACCOUNT->value    => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'Investment_Other'],
        AccountSubtype::CLEARING_ACCOUNT->value      => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'UndepositedFunds'],
        AccountSubtype::TRANSIT_ACCOUNT->value       => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountSubtype::OFFSET_ACCOUNT->value        => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountSubtype::SUSPENSE_ACCOUNT->value      => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountSubtype::MEMORANDUM_ACCOUNT->value    => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountSubtype::OTHER->value                 => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],

        // ----- FIXED ASSET -----
        AccountSubtype::FIXED_ASSET->value           => ['AccountType' => 'Fixed Asset',             'AccountSubType' => 'FurnitureAndEquipment'],

        // ----- OTHER ASSET -----
        AccountSubtype::CONTRA_ACCOUNT->value        => ['AccountType' => 'Other Asset',             'AccountSubType' => 'OtherLongTermAssets'],
        AccountSubtype::FOREX_ACCOUNT->value         => ['AccountType' => 'Other Asset',             'AccountSubType' => 'OtherLongTermAssets'],

        // ----- ACCOUNTS PAYABLE -----
        AccountSubtype::ACCOUNTS_PAYABLE->value      => ['AccountType' => 'Accounts Payable',        'AccountSubType' => 'AccountsPayable'],

        // ----- CREDIT CARD -----
        AccountSubtype::CREDIT_CARD_PAYABLE->value   => ['AccountType' => 'Credit Card',             'AccountSubType' => 'CreditCard'],

        // ----- OTHER CURRENT LIABILITY -----
        AccountSubtype::CURRENT_LIABILITY->value     => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'OtherCurrentLiabilities'],
        AccountSubtype::SHORT_TERM_LIABILITY->value  => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'LoanPayable'],
        AccountSubtype::ACCRUAL_ACCOUNT->value       => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'OtherCurrentLiabilities'],
        AccountSubtype::SALARIES_PAYABLE->value      => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'DirectDepositPayable'],
        AccountSubtype::WITHHOLDING_TAX_PAYABLE->value => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'SalesTaxPayable'],
        AccountSubtype::TAX_ACCOUNT->value           => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'SalesTaxPayable'],

        // ----- LONG TERM LIABILITY -----
        AccountSubtype::LONG_TERM_LIABILITY->value   => ['AccountType' => 'Long Term Liability',     'AccountSubType' => 'LongTermLiabilities'],
        AccountSubtype::LOAN_ACCOUNT->value          => ['AccountType' => 'Long Term Liability',     'AccountSubType' => 'NotesPayable'],
        AccountSubtype::MORTGAGE_ACCOUNT->value      => ['AccountType' => 'Long Term Liability',     'AccountSubType' => 'NotesPayable'],
        AccountSubtype::PROVISION_ACCOUNT->value     => ['AccountType' => 'Long Term Liability',     'AccountSubType' => 'LongTermLiabilities'],

        // ----- EQUITY -----
        AccountSubtype::EQUITY->value                      => ['AccountType' => 'Equity', 'AccountSubType' => 'OwnersEquity'],
        AccountSubtype::CAPITAL_ACCOUNT->value             => ['AccountType' => 'Equity', 'AccountSubType' => 'OwnersEquity'],
        AccountSubtype::RETAINED_EARNINGS_ACCOUNT->value   => ['AccountType' => 'Equity', 'AccountSubType' => 'RetainedEarnings'],
        AccountSubtype::RESERVE_ACCOUNT->value             => ['AccountType' => 'Equity', 'AccountSubType' => 'OwnersEquity'],
        AccountSubtype::DIVIDEND_ACCOUNT->value            => ['AccountType' => 'Equity', 'AccountSubType' => 'OwnersEquity'],
        AccountSubtype::DRAWINGS_ACCOUNT->value            => ['AccountType' => 'Equity', 'AccountSubType' => 'OwnersEquity'],

        // ----- INCOME -----
        AccountSubtype::INCOME->value              => ['AccountType' => 'Income', 'AccountSubType' => 'OtherPrimaryIncome'],
        AccountSubtype::OPERATING_REVENUE->value   => ['AccountType' => 'Income', 'AccountSubType' => 'SalesOfProductIncome'],

        // ----- OTHER INCOME -----
        AccountSubtype::NON_OPERATING_REVENUE->value => ['AccountType' => 'Other Income', 'AccountSubType' => 'OtherMiscellaneousIncome'],

        // ----- COST OF GOODS SOLD -----
        AccountSubtype::COST_OF_GOODS_SOLD->value  => ['AccountType' => 'Cost of Goods Sold', 'AccountSubType' => 'SuppliesMaterialsCogs'],

        // ----- EXPENSE -----
        AccountSubtype::EXPENSE->value                            => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::OPERATING_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::SELLING_EXPENSE->value                    => ['AccountType' => 'Expense', 'AccountSubType' => 'CommissionsAndFees'],
        AccountSubtype::GENERAL_AND_ADMINISTRATIVE_EXPENSE->value => ['AccountType' => 'Expense', 'AccountSubType' => 'OfficeGeneralAdministrativeExpenses'],
        AccountSubtype::SALARIES_AND_WAGES_EXPENSE->value         => ['AccountType' => 'Expense', 'AccountSubType' => 'PayrollExpenses'],
        AccountSubtype::EMPLOYEE_BENEFITS_EXPENSE->value          => ['AccountType' => 'Expense', 'AccountSubType' => 'PayrollExpenses'],
        AccountSubtype::PENSION_EXPENSE->value                    => ['AccountType' => 'Expense', 'AccountSubType' => 'PayrollExpenses'],
        AccountSubtype::PAYROLL_ACCOUNT->value                    => ['AccountType' => 'Expense', 'AccountSubType' => 'PayrollExpenses'],
        AccountSubtype::RENT_EXPENSE->value                       => ['AccountType' => 'Expense', 'AccountSubType' => 'Rent'],
        AccountSubtype::UTILITIES_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'UtilitiesExpense'],
        AccountSubtype::INTERNET_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'UtilitiesExpense'],
        AccountSubtype::TELECOMMUNICATIONS_EXPENSE->value         => ['AccountType' => 'Expense', 'AccountSubType' => 'UtilitiesExpense'],
        AccountSubtype::OFFICE_EXPENSE->value                     => ['AccountType' => 'Expense', 'AccountSubType' => 'OfficeExpenses'],
        AccountSubtype::OFFICE_SUPPLIES_EXPENSE->value            => ['AccountType' => 'Expense', 'AccountSubType' => 'OfficeExpenses'],
        AccountSubtype::SUPPLIES_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'OfficeExpenses'],
        AccountSubtype::ADVERTISING_EXPENSE->value                => ['AccountType' => 'Expense', 'AccountSubType' => 'AdvertisingPromotional'],
        AccountSubtype::MARKETING_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'AdvertisingPromotional'],
        AccountSubtype::TRAVEL_EXPENSE->value                     => ['AccountType' => 'Expense', 'AccountSubType' => 'TravelExpenses'],
        AccountSubtype::FOOD_AND_ENTERTAINMENT_EXPENSE->value     => ['AccountType' => 'Expense', 'AccountSubType' => 'Entertainment'],
        AccountSubtype::TRAINING_AND_EDUCATION_EXPENSE->value     => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::RECRUITMENT_EXPENSE->value                => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::LEGAL_AND_PROFESSIONAL_FEES_EXPENSE->value => ['AccountType' => 'Expense', 'AccountSubType' => 'LegalAndProfessionalFees'],
        AccountSubtype::CONSULTING_EXPENSE->value                 => ['AccountType' => 'Expense', 'AccountSubType' => 'LegalAndProfessionalFees'],
        AccountSubtype::SOFTWARE_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'OfficeExpenses'],
        AccountSubtype::EQUIPMENT_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'EquipmentRental'],
        AccountSubtype::EQUIPMENT_RENTAL_EXPENSE->value           => ['AccountType' => 'Expense', 'AccountSubType' => 'EquipmentRental'],
        AccountSubtype::VEHICLE_RENTAL_EXPENSE->value             => ['AccountType' => 'Expense', 'AccountSubType' => 'EquipmentRental'],
        AccountSubtype::TRUCK_AND_VEHICLE_EXPENSE->value          => ['AccountType' => 'Expense', 'AccountSubType' => 'EquipmentRental'],
        AccountSubtype::CLEANING_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::SECURITY_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountSubtype::INSURANCE_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'Insurance'],
        AccountSubtype::MAINTENANCE_AND_REPAIR_EXPENSE->value     => ['AccountType' => 'Expense', 'AccountSubType' => 'RepairMaintenance'],
        AccountSubtype::BANK_FEES_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'BankCharges'],
        AccountSubtype::POSTAGE_AND_SHIPPING_EXPENSE->value       => ['AccountType' => 'Expense', 'AccountSubType' => 'ShippingFreightAndDeliveryExpense'],
        AccountSubtype::TAXES_AND_LICENSES_EXPENSE->value         => ['AccountType' => 'Expense', 'AccountSubType' => 'TaxesPaid'],
        AccountSubtype::LICENSES_AND_PERMITS_EXPENSE->value       => ['AccountType' => 'Expense', 'AccountSubType' => 'TaxesPaid'],
        AccountSubtype::DONATION_EXPENSE->value                   => ['AccountType' => 'Expense', 'AccountSubType' => 'CharitableContributions'],
        AccountSubtype::CHARITABLE_CONTRIBUTIONS_EXPENSE->value   => ['AccountType' => 'Expense', 'AccountSubType' => 'CharitableContributions'],
        AccountSubtype::BAD_DEBTS_EXPENSE->value                  => ['AccountType' => 'Expense', 'AccountSubType' => 'BadDebts'],

        // ----- OTHER EXPENSE -----
        AccountSubtype::NON_OPERATING_EXPENSE->value => ['AccountType' => 'Other Expense', 'AccountSubType' => 'OtherExpense'],
        AccountSubtype::DEPRECIATION_EXPENSE->value  => ['AccountType' => 'Other Expense', 'AccountSubType' => 'Depreciation'],
        AccountSubtype::INTEREST_EXPENSE->value      => ['AccountType' => 'Other Expense', 'AccountSubType' => 'InterestPaid'],
        AccountSubtype::FINANCE_COST->value          => ['AccountType' => 'Other Expense', 'AccountSubType' => 'FinanceCosts'],
        AccountSubtype::TAX_EXPENSE->value           => ['AccountType' => 'Other Expense', 'AccountSubType' => 'IncomeTaxExpense'],
        AccountSubtype::OTHER_EXPENSE->value         => ['AccountType' => 'Other Expense', 'AccountSubType' => 'OtherExpense'],
    ];

    /**
     * Fallback: AccountType (when subtype is null or unmapped) → QBO AccountType
     */
    private const TYPE_FALLBACK = [
        AccountType::ASSET->value     => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
        AccountType::LIABILITY->value => ['AccountType' => 'Other Current Liability', 'AccountSubType' => 'OtherCurrentLiabilities'],
        AccountType::EQUITY->value    => ['AccountType' => 'Equity',                  'AccountSubType' => 'OwnersEquity'],
        AccountType::REVENUE->value   => ['AccountType' => 'Income',                  'AccountSubType' => 'OtherPrimaryIncome'],
        AccountType::EXPENSE->value   => ['AccountType' => 'Expense',                 'AccountSubType' => 'OtherBusinessExpenses'],
        AccountType::OTHER->value     => ['AccountType' => 'Other Current Asset',     'AccountSubType' => 'OtherCurrentAssets'],
    ];

    /** Map an account model (or raw type/subtype strings) to QBO classification. */
    public function map(mixed $account): array
    {
        $subtype = $account instanceof \BackedEnum
            ? $account->value
            : (is_object($account) ? ($account->subtype instanceof \BackedEnum ? $account->subtype->value : (string) ($account->subtype ?? '')) : (string) $account);

        if ($subtype !== '' && isset(self::SUBTYPE_MAP[$subtype])) {
            return self::SUBTYPE_MAP[$subtype];
        }

        // Fall back to AccountType
        $type = is_object($account) && ! $account instanceof \BackedEnum
            ? ($account->type instanceof \BackedEnum ? $account->type->value : (string) ($account->type ?? ''))
            : '';

        return self::TYPE_FALLBACK[$type] ?? ['AccountType' => 'Other Current Asset', 'AccountSubType' => 'OtherCurrentAssets'];
    }

    /** Return just the QBO AccountType string for a given account. */
    public function qboType(mixed $account): string
    {
        return $this->map($account)['AccountType'];
    }

    /** Return just the QBO AccountSubType string for a given account. */
    public function qboSubType(mixed $account): string
    {
        return $this->map($account)['AccountSubType'];
    }

    /**
     * Determine which QBO P&L / Balance Sheet section an account belongs to.
     *
     * Returns one of:
     *   bank | accounts_receivable | other_current_asset | fixed_asset | other_asset
     *   accounts_payable | credit_card | other_current_liability | long_term_liability
     *   equity | income | other_income | cogs | expense | other_expense
     */
    public function section(mixed $account): string
    {
        return match ($this->qboType($account)) {
            'Bank'                    => 'bank',
            'Accounts Receivable'     => 'accounts_receivable',
            'Other Current Asset'     => 'other_current_asset',
            'Fixed Asset'             => 'fixed_asset',
            'Other Asset'             => 'other_asset',
            'Accounts Payable'        => 'accounts_payable',
            'Credit Card'             => 'credit_card',
            'Other Current Liability' => 'other_current_liability',
            'Long Term Liability'     => 'long_term_liability',
            'Equity'                  => 'equity',
            'Income'                  => 'income',
            'Other Income'            => 'other_income',
            'Cost of Goods Sold'      => 'cogs',
            'Expense'                 => 'expense',
            'Other Expense'           => 'other_expense',
            default                   => 'other_current_asset',
        };
    }
}
