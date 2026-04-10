<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Enums;

use Centrex\Accounting\Concerns\EnumHelpers;

/**
 * Account Subtypes (IFRS-aligned, reporting-safe).
 *
 * Purpose:
 * - Functional classification of GL accounts
 * - Mapping to IFRS financial statements
 * - Granular internal analytics
 *
 * NOTE:
 * - Not all subtypes are suitable for published financials.
 * - Technical accounts must be excluded from external reports.
 */
enum AccountSubtype: string
{
    use EnumHelpers;

    /* -----------------------------------------------------------------
     | CASH & BANK (IAS 7)
     |------------------------------------------------------------------*/
    case CASH = 'cash';
    case PETTY_CASH_ACCOUNT = 'petty_cash_account';

    case CHECKING_ACCOUNT = 'checking_account';
    case SAVINGS_ACCOUNT = 'savings_account';
    case MONEY_MARKET_ACCOUNT = 'money_market_account';
    case ESCROW_ACCOUNT = 'escrow_account';

    /* -----------------------------------------------------------------
     | ASSETS
     |------------------------------------------------------------------*/
    case CURRENT_ASSET = 'current_asset';          // Generic / internal use
    case FIXED_ASSET = 'fixed_asset';              // PPE (IAS 16)
    case INVESTMENT_ACCOUNT = 'investment_account';

    case ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    case PREPAID_EXPENSES = 'prepaid_expenses';

    /* -----------------------------------------------------------------
     | LIABILITIES
     |------------------------------------------------------------------*/
    case CURRENT_LIABILITY = 'current_liability';  // Internal grouping
    case SHORT_TERM_LIABILITY = 'short_term_liability';
    case LONG_TERM_LIABILITY = 'long_term_liability';

    case ACCOUNTS_PAYABLE = 'accounts_payable';
    case CREDIT_CARD_PAYABLE = 'credit_card_payable';

    case LOAN_ACCOUNT = 'loan_account';
    case MORTGAGE_ACCOUNT = 'mortgage_account';

    case ACCRUAL_ACCOUNT = 'accrual_account';
    case PROVISION_ACCOUNT = 'provision_account';  // IAS 37

    // Liabilities – Payroll
    case SALARIES_PAYABLE = 'salaries_payable';
    case WITHHOLDING_TAX_PAYABLE = 'withholding_tax_payable';

    /* -----------------------------------------------------------------
     | EQUITY
     |------------------------------------------------------------------*/
    case EQUITY = 'equity';                      // Generic / internal use
    case CAPITAL_ACCOUNT = 'capital_account';
    case RETAINED_EARNINGS_ACCOUNT = 'retained_earnings_account';
    case RESERVE_ACCOUNT = 'reserve_account';
    case DIVIDEND_ACCOUNT = 'dividend_account';
    case DRAWINGS_ACCOUNT = 'drawings_account';

    /* -----------------------------------------------------------------
     | REVENUE (IFRS 15)
     |------------------------------------------------------------------*/
    case INCOME = 'income';                    // Generic / internal use
    case OPERATING_REVENUE = 'operating_revenue';
    case NON_OPERATING_REVENUE = 'non_operating_revenue';

    /* -----------------------------------------------------------------
     | COST OF SALES (IAS 2)
     |------------------------------------------------------------------*/
    case COST_OF_GOODS_SOLD = 'cost_of_goods_sold';

    /* -----------------------------------------------------------------
     | OPERATING EXPENSES (IAS 1)
     |------------------------------------------------------------------*/
    case EXPENSE = 'expense';                  // Generic / internal use
    case OPERATING_EXPENSE = 'operating_expense';
    case NON_OPERATING_EXPENSE = 'non_operating_expense';
    case SELLING_EXPENSE = 'selling_expense';
    case GENERAL_AND_ADMINISTRATIVE_EXPENSE = 'general_and_administrative_expense';

    case SALARIES_AND_WAGES_EXPENSE = 'salaries_and_wages_expense';
    case EMPLOYEE_BENEFITS_EXPENSE = 'employee_benefits_expense';
    case PENSION_EXPENSE = 'pension_expense';
    case PAYROLL_ACCOUNT = 'payroll_account';

    case RENT_EXPENSE = 'rent_expense';
    case UTILITIES_EXPENSE = 'utilities_expense';
    case INTERNET_EXPENSE = 'internet_expense';
    case TELECOMMUNICATIONS_EXPENSE = 'telecommunications_expense';

    case OFFICE_EXPENSE = 'office_expense';
    case OFFICE_SUPPLIES_EXPENSE = 'office_supplies_expense';
    case SUPPLIES_EXPENSE = 'supplies_expense';

    case ADVERTISING_EXPENSE = 'advertising_expense';
    case MARKETING_EXPENSE = 'marketing_expense';

    case TRAVEL_EXPENSE = 'travel_expense';
    case FOOD_AND_ENTERTAINMENT_EXPENSE = 'food_and_entertainment_expense';

    case TRAINING_AND_EDUCATION_EXPENSE = 'training_and_education_expense';
    case RECRUITMENT_EXPENSE = 'recruitment_expense';

    case LEGAL_AND_PROFESSIONAL_FEES_EXPENSE = 'legal_and_professional_fees_expense';
    case CONSULTING_EXPENSE = 'consulting_expense';

    case SOFTWARE_EXPENSE = 'software_expense';
    case EQUIPMENT_EXPENSE = 'equipment_expense';
    case EQUIPMENT_RENTAL_EXPENSE = 'equipment_rental_expense';

    case VEHICLE_RENTAL_EXPENSE = 'vehicle_rental_expense';
    case TRUCK_AND_VEHICLE_EXPENSE = 'truck_and_vehicle_expense';

    case CLEANING_EXPENSE = 'cleaning_expense';
    case SECURITY_EXPENSE = 'security_expense';
    case INSURANCE_EXPENSE = 'insurance_expense';

    case MAINTENANCE_AND_REPAIR_EXPENSE = 'maintenance_and_repair_expense';

    case BANK_FEES_EXPENSE = 'bank_fees_expense';
    case POSTAGE_AND_SHIPPING_EXPENSE = 'postage_and_shipping_expense';

    case TAXES_AND_LICENSES_EXPENSE = 'taxes_and_licenses_expense';
    case LICENSES_AND_PERMITS_EXPENSE = 'licenses_and_permits_expense';

    case DONATION_EXPENSE = 'donation_expense';
    case CHARITABLE_CONTRIBUTIONS_EXPENSE = 'charitable_contributions_expense';

    case BAD_DEBTS_EXPENSE = 'bad_debts_expense';
    case DEPRECIATION_EXPENSE = 'depreciation_expense';

    /* -----------------------------------------------------------------
     | FINANCE COSTS (IAS 23)
     |------------------------------------------------------------------*/
    case INTEREST_EXPENSE = 'interest_expense';
    case FINANCE_COST = 'finance_cost';

    /* -----------------------------------------------------------------
     | TAX
     |------------------------------------------------------------------*/
    case TAX_EXPENSE = 'tax_expense';
    case TAX_ACCOUNT = 'tax_account';

    /* -----------------------------------------------------------------
     | FOREIGN EXCHANGE (IAS 21)
     |------------------------------------------------------------------*/
    case FOREX_ACCOUNT = 'forex_account';

    /* -----------------------------------------------------------------
     | TECHNICAL / CONTROL ACCOUNTS (NON-REPORTABLE)
     |------------------------------------------------------------------*/
    case CONTRA_ACCOUNT = 'contra_account';
    case CLEARING_ACCOUNT = 'clearing_account';
    case TRANSIT_ACCOUNT = 'transit_account';
    case OFFSET_ACCOUNT = 'offset_account';

    case SUSPENSE_ACCOUNT = 'suspense_account';
    case SUSPENSE_CASH_ACCOUNT = 'suspense_cash_account';

    case MEMORANDUM_ACCOUNT = 'memorandum_account';

    /* -----------------------------------------------------------------
     | FALLBACK
     |------------------------------------------------------------------*/
    case OTHER_EXPENSE = 'other_expense';
    case OTHER = 'other';
}
