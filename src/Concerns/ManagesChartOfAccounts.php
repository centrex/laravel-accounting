<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Concerns;

use Centrex\Accounting\Models\Account;

trait ManagesChartOfAccounts
{
    /** Initialize standard Chart of Accounts (idempotent). */
    public function initializeChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                    'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1100', 'name' => 'Bank Account',            'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',     'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory',               'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1450', 'name' => 'Employee Loans & Advances Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1500', 'name' => 'Prepaid Expenses',        'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1700', 'name' => 'Fixed Assets',            'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '1800', 'name' => 'Accumulated Depreciation', 'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',        'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2050', 'name' => 'Goods Received Not Invoiced', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2100', 'name' => 'Credit Card Payable',     'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2150', 'name' => 'Inventory Financing Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2170', 'name' => 'Accrued Interest — Inventory Financing', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2200', 'name' => 'Accrued Expenses',        'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',              'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2400', 'name' => 'Short-term Loans Payable',      'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2420', 'name' => 'Accrued Interest — Short-term Loans', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2500', 'name' => 'Long-term Loans Payable',       'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '2520', 'name' => 'Accrued Interest — Long-term Loans',  'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '3000', 'name' => "Owner's Equity",          'type' => 'equity',    'subtype' => 'capital_account'],
            ['code' => '3100', 'name' => 'Retained Earnings',       'type' => 'equity',    'subtype' => 'retained_earnings_account'],
            ['code' => '3200', 'name' => "Owner's Draw",            'type' => 'equity',    'subtype' => 'drawings_account'],
            ['code' => '4000', 'name' => 'Sales Revenue',           'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '4100', 'name' => 'Service Revenue',         'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '4900', 'name' => 'Other Income',            'type' => 'revenue',   'subtype' => 'non_operating_revenue'],
            ['code' => '4910', 'name' => 'Gain/Loss on Disposal of Fixed Assets', 'type' => 'revenue', 'subtype' => 'non_operating_revenue'],
            ['code' => '4210', 'name' => 'Delivery Charge',         'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '4220', 'name' => 'Cash on Delivery Charge', 'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold',      'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
            ['code' => '5500', 'name' => 'Purchase Discount',       'type' => 'expense',   'subtype' => 'cost_of_goods_sold'],
            ['code' => '5501', 'name' => 'Early Payment Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5502', 'name' => 'Volume Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5503', 'name' => 'Trade Discount (Purchase)', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5504', 'name' => 'Purchase Returns & Allowances', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '5505', 'name' => 'Inventory Shrinkage & Write-offs', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
            ['code' => '6000', 'name' => 'Salaries & Wages',        'type' => 'expense',   'subtype' => 'salaries_and_wages_expense'],
            ['code' => '6100', 'name' => 'Rent Expense',            'type' => 'expense',   'subtype' => 'rent_expense'],
            // Contra-revenue (IFRS 15 variable consideration), not operating expenses —
            // netted against Sales Revenue (4000) in getIncomeStatement() so Gross Profit
            // reflects Net Revenue - COGS. See AccountSubtype::CONTRA_REVENUE.
            ['code' => '6130', 'name' => 'Sales Discount',          'type' => 'revenue',   'subtype' => 'contra_revenue'],
            ['code' => '6131', 'name' => 'Early Payment Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6132', 'name' => 'Volume Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6133', 'name' => 'Promotional Discount (Sales)', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'revenue', 'subtype' => 'contra_revenue'],
            ['code' => '6200', 'name' => 'Utilities',               'type' => 'expense',   'subtype' => 'utilities_expense'],
            ['code' => '6300', 'name' => 'Office Supplies',         'type' => 'expense',   'subtype' => 'office_supplies_expense'],
            ['code' => '6310', 'name' => 'Courier Bill / Charge',   'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6320', 'name' => 'Shipping / Transfer Bill (Carriage)', 'type' => 'expense', 'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6330', 'name' => 'Local Delivery Charge',    'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6340', 'name' => 'Delivery Return Charge',  'type' => 'expense',   'subtype' => 'postage_and_shipping_expense'],
            ['code' => '6400', 'name' => 'Insurance',               'type' => 'expense',   'subtype' => 'insurance_expense'],
            ['code' => '6500', 'name' => 'Marketing & Advertising', 'type' => 'expense',   'subtype' => 'marketing_expense'],
            ['code' => '6600', 'name' => 'Depreciation',            'type' => 'expense',   'subtype' => 'depreciation_expense'],
            ['code' => '6700', 'name' => 'Interest Expense',        'type' => 'expense',   'subtype' => 'interest_expense'],
            ['code' => '6710', 'name' => 'Interest Expense — Inventory Financing', 'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6720', 'name' => 'Interest Expense — Short-term Loans',   'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6730', 'name' => 'Interest Expense — Long-term Loans',    'type' => 'expense', 'subtype' => 'interest_expense'],
            ['code' => '6800', 'name' => 'Bank Fees',               'type' => 'expense',   'subtype' => 'bank_fees_expense'],
            ['code' => '7100', 'name' => 'Consultancy Fee',         'type' => 'expense',   'subtype' => 'consulting_expense'],
            ['code' => '7200', 'name' => 'Donation Expense',        'type' => 'expense',   'subtype' => 'donation_expense'],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['code' => $accountData['code']],
                array_merge($accountData, ['is_system' => true]),
            );
        }

        // Wire parent relationships for sub-accounts
        $parentMap = [
            '6710' => '6700',  // Inv. Financing Interest → Interest Expense
            '6720' => '6700',  // Short-term Loan Interest → Interest Expense
            '6730' => '6700',  // Long-term Loan Interest → Interest Expense
        ];

        foreach ($parentMap as $childCode => $parentCode) {
            $parent = Account::where('code', $parentCode)->first();
            $child = Account::where('code', $childCode)->first();

            if ($parent && $child && $child->parent_id === null) {
                $child->update(['parent_id' => $parent->id]);
            }
        }
    }
}
