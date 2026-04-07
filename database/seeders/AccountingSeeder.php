<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Database\Seeders;

use Carbon\Carbon;
use Centrex\LaravelAccounting\Enums\{
    AccountSubtype,
    AccountType
};
use Centrex\LaravelAccounting\Models\{
    Account,
    Customer,
    FiscalPeriod,
    FiscalYear,
    TaxRate,
    Vendor
};
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedChartOfAccounts();
        $this->seedFiscalYears();
        $this->seedCustomers();
        $this->seedVendors();
        $this->seedTaxRates();
    }

    protected function seedChartOfAccounts(): void
    {
        $accounts = [

            /* ==========================================================
             | ASSETS (1000–1999)
             ==========================================================*/
            [
                'code'      => '1000',
                'name'      => 'Cash',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::CASH,
                'is_system' => true,
            ],
            [
                'code'      => '1050',
                'name'      => 'Petty Cash',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::PETTY_CASH_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '1100',
                'name'      => 'Bank – Operating',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::CHECKING_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '1200',
                'name'      => 'Accounts Receivable',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::ACCOUNTS_RECEIVABLE,
                'is_system' => true,
            ],
            [
                'code'      => '1250',
                'name'      => 'Allowance for Doubtful Accounts',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::CONTRA_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '1300',
                'name'      => 'Inventory',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::CURRENT_ASSET,
                'is_system' => true,
            ],
            [
                'code'      => '1500',
                'name'      => 'Prepaid Expenses',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::PREPAID_EXPENSES,
                'is_system' => true,
            ],
            [
                'code'      => '1700',
                'name'      => 'Property, Plant & Equipment',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::FIXED_ASSET,
                'is_system' => true,
            ],
            [
                'code'      => '1800',
                'name'      => 'Accumulated Depreciation',
                'type'      => AccountType::ASSET,
                'subtype'   => AccountSubtype::CONTRA_ACCOUNT,
                'is_system' => true,
            ],

            /* ==========================================================
             | LIABILITIES (2000–2999)
             ==========================================================*/
            [
                'code'      => '2000',
                'name'      => 'Accounts Payable',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::ACCOUNTS_PAYABLE,
                'is_system' => true,
            ],
            [
                'code'      => '2100',
                'name'      => 'Credit Card Payable',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::CREDIT_CARD_PAYABLE,
                'is_system' => true,
            ],
            [
                'code'      => '2200',
                'name'      => 'Accrued Expenses',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::ACCRUAL_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '2250',
                'name'      => 'Salaries Payable',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::SALARIES_PAYABLE,
                'is_system' => true,
            ],
            [
                'code'      => '2300',
                'name'      => 'Sales Tax Payable',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::TAX_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '2400',
                'name'      => 'Income Tax Payable',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::WITHHOLDING_TAX_PAYABLE,
                'is_system' => true,
            ],
            [
                'code'      => '2500',
                'name'      => 'Long-term Loan',
                'type'      => AccountType::LIABILITY,
                'subtype'   => AccountSubtype::LOAN_ACCOUNT,
                'is_system' => true,
            ],

            /* ==========================================================
             | EQUITY (3000–3999)
             ==========================================================*/
            [
                'code'      => '3000',
                'name'      => 'Capital',
                'type'      => AccountType::EQUITY,
                'subtype'   => AccountSubtype::CAPITAL_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '3100',
                'name'      => 'Retained Earnings',
                'type'      => AccountType::EQUITY,
                'subtype'   => AccountSubtype::RETAINED_EARNINGS_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '3200',
                'name'      => 'Owner Drawings',
                'type'      => AccountType::EQUITY,
                'subtype'   => AccountSubtype::DRAWINGS_ACCOUNT,
                'is_system' => true,
            ],
            [
                'code'      => '3900',
                'name'      => 'Income Summary',
                'type'      => AccountType::EQUITY,
                'subtype'   => AccountSubtype::MEMORANDUM_ACCOUNT,
                'is_system' => true,
            ],

            /* ==========================================================
             | REVENUE (4000–4999)
             ==========================================================*/
            [
                'code'      => '4000',
                'name'      => 'Sales Revenue',
                'type'      => AccountType::REVENUE,
                'subtype'   => AccountSubtype::OPERATING_REVENUE,
                'is_system' => true,
            ],
            [
                'code'      => '4100',
                'name'      => 'Service Revenue',
                'type'      => AccountType::REVENUE,
                'subtype'   => AccountSubtype::OPERATING_REVENUE,
                'is_system' => true,
            ],
            [
                'code'      => '4900',
                'name'      => 'Other Income',
                'type'      => AccountType::REVENUE,
                'subtype'   => AccountSubtype::NON_OPERATING_REVENUE,
                'is_system' => true,
            ],

            /* ==========================================================
             | EXPENSES (5000–6999)
             ==========================================================*/
            [
                'code'      => '5000',
                'name'      => 'Cost of Goods Sold',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::COST_OF_GOODS_SOLD,
                'is_system' => true,
            ],
            [
                'code'      => '6000',
                'name'      => 'Salaries & Wages',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::SALARIES_AND_WAGES_EXPENSE,
                'is_system' => true,
            ],
            [
                'code'      => '6100',
                'name'      => 'Rent Expense',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::RENT_EXPENSE,
                'is_system' => true,
            ],
            [
                'code'      => '6200',
                'name'      => 'Utilities Expense',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::UTILITIES_EXPENSE,
                'is_system' => true,
            ],
            [
                'code'      => '6600',
                'name'      => 'Depreciation Expense',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::DEPRECIATION_EXPENSE,
                'is_system' => true,
            ],
            [
                'code'      => '6700',
                'name'      => 'Interest Expense',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::INTEREST_EXPENSE,
                'is_system' => true,
            ],
            [
                'code'      => '6800',
                'name'      => 'Bank Charges',
                'type'      => AccountType::EXPENSE,
                'subtype'   => AccountSubtype::BANK_FEES_EXPENSE,
                'is_system' => true,
            ],
        ];

        foreach ($accounts as $account) {
            Account::firstOrCreate(['code' => $account['code']], $account);
        }
    }

    protected function seedFiscalYears(): void
    {
        $year = now()->year;

        for ($i = -1; $i <= 1; $i++) {
            $fy = FiscalYear::firstOrCreate(
                ['name' => 'FY ' . ($year + $i)],
                [
                    'start_date' => Carbon::create($year + $i, 1, 1),
                    'end_date'   => Carbon::create($year + $i, 12, 31),
                    'is_current' => $i === 0,
                    'is_closed'  => $i < 0,
                ],
            );

            for ($m = 1; $m <= 12; $m++) {
                $start = Carbon::create($year + $i, $m, 1);
                FiscalPeriod::firstOrCreate(
                    [
                        'fiscal_year_id' => $fy->id,
                        'name'           => $start->format('F Y'),
                    ],
                    [
                        'start_date' => $start,
                        'end_date'   => $start->copy()->endOfMonth(),
                        'is_closed'  => $i < 0,
                    ],
                );
            }
        }
    }

    protected function seedCustomers()
    {
        $customers = [
            [
                'code'          => 'CUST001',
                'name'          => 'Acme Corporation',
                'email'         => 'billing@acmecorp.com',
                'phone'         => '+1-555-0100',
                'address'       => '123 Business St',
                'city'          => 'New York',
                'country'       => 'USA',
                'tax_id'        => '12-3456789',
                'credit_limit'  => 50000,
                'payment_terms' => 30,
            ],
            [
                'code'          => 'CUST002',
                'name'          => 'Global Industries Ltd',
                'email'         => 'accounts@globalindustries.com',
                'phone'         => '+1-555-0200',
                'address'       => '456 Commerce Ave',
                'city'          => 'Los Angeles',
                'country'       => 'USA',
                'tax_id'        => '98-7654321',
                'credit_limit'  => 75000,
                'payment_terms' => 60,
            ],
            [
                'code'          => 'CUST003',
                'name'          => 'Tech Solutions Inc',
                'email'         => 'payables@techsolutions.com',
                'phone'         => '+1-555-0300',
                'address'       => '789 Innovation Blvd',
                'city'          => 'San Francisco',
                'country'       => 'USA',
                'credit_limit'  => 100000,
                'payment_terms' => 45,
            ],
        ];

        foreach ($customers as $customerData) {
            Customer::firstOrCreate(
                ['code' => $customerData['code']],
                $customerData,
            );
        }
    }

    protected function seedVendors()
    {
        $vendors = [
            [
                'code'          => 'VEND001',
                'name'          => 'Office Supplies Co',
                'email'         => 'sales@officesupplies.com',
                'phone'         => '+1-555-1100',
                'address'       => '321 Supply St',
                'city'          => 'Chicago',
                'country'       => 'USA',
                'tax_id'        => '11-2233445',
                'payment_terms' => 30,
            ],
            [
                'code'          => 'VEND002',
                'name'          => 'Utilities Provider',
                'email'         => 'billing@utilities.com',
                'phone'         => '+1-555-1200',
                'address'       => '654 Energy Ave',
                'city'          => 'Houston',
                'country'       => 'USA',
                'payment_terms' => 15,
            ],
            [
                'code'          => 'VEND003',
                'name'          => 'Equipment Rentals LLC',
                'email'         => 'rental@equipment.com',
                'phone'         => '+1-555-1300',
                'address'       => '987 Rental Rd',
                'city'          => 'Dallas',
                'country'       => 'USA',
                'payment_terms' => 30,
            ],
        ];

        foreach ($vendors as $vendorData) {
            Vendor::firstOrCreate(
                ['code' => $vendorData['code']],
                $vendorData,
            );
        }
    }

    protected function seedTaxRates()
    {
        $taxRates = [
            [
                'name'        => 'Standard Sales Tax',
                'code'        => 'STD',
                'rate'        => 8.50,
                'is_compound' => false,
                'is_active'   => true,
            ],
            [
                'name'        => 'Reduced Rate',
                'code'        => 'REDUCED',
                'rate'        => 5.00,
                'is_compound' => false,
                'is_active'   => true,
            ],
            [
                'name'        => 'Zero Rated',
                'code'        => 'ZERO',
                'rate'        => 0.00,
                'is_compound' => false,
                'is_active'   => true,
            ],
            [
                'name'        => 'VAT Standard',
                'code'        => 'VAT',
                'rate'        => 20.00,
                'is_compound' => false,
                'is_active'   => true,
            ],
        ];

        foreach ($taxRates as $taxData) {
            TaxRate::firstOrCreate(
                ['code' => $taxData['code']],
                $taxData,
            );
        }
    }
}
