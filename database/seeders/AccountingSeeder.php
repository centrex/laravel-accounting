<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\Customer;
use App\Models\Vendor;
use App\Models\TaxRate;
use Carbon\Carbon;

class AccountingSeeder extends Seeder
{
    public function run()
    {
        $this->seedChartOfAccounts();
        $this->seedFiscalYears();
        $this->seedCustomers();
        $this->seedVendors();
        $this->seedTaxRates();
    }

    protected function seedChartOfAccounts()
    {
        $accounts = [
            // ASSETS (1000-1999)
            [
                'code' => '1000',
                'name' => 'Cash',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Cash on hand and in bank',
                'is_system' => true
            ],
            [
                'code' => '1050',
                'name' => 'Petty Cash',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'parent_id' => null,
                'is_system' => true
            ],
            [
                'code' => '1100',
                'name' => 'Bank Account - Operating',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Primary business checking account',
                'is_system' => true
            ],
            [
                'code' => '1200',
                'name' => 'Accounts Receivable',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Money owed by customers',
                'is_system' => true
            ],
            [
                'code' => '1300',
                'name' => 'Inventory',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Goods held for sale',
                'is_system' => true
            ],
            [
                'code' => '1400',
                'name' => 'Allowance for Doubtful Accounts',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Estimated uncollectible receivables',
                'is_system' => true
            ],
            [
                'code' => '1500',
                'name' => 'Prepaid Expenses',
                'type' => 'asset',
                'subtype' => 'current_asset',
                'description' => 'Expenses paid in advance',
                'is_system' => true
            ],
            [
                'code' => '1700',
                'name' => 'Fixed Assets',
                'type' => 'asset',
                'subtype' => 'fixed_asset',
                'description' => 'Long-term tangible assets',
                'is_system' => true
            ],
            [
                'code' => '1750',
                'name' => 'Equipment',
                'type' => 'asset',
                'subtype' => 'fixed_asset',
                'is_system' => true
            ],
            [
                'code' => '1800',
                'name' => 'Accumulated Depreciation',
                'type' => 'asset',
                'subtype' => 'fixed_asset',
                'description' => 'Contra-asset account for depreciation',
                'is_system' => true
            ],

            // LIABILITIES (2000-2999)
            [
                'code' => '2000',
                'name' => 'Accounts Payable',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'description' => 'Money owed to vendors',
                'is_system' => true
            ],
            [
                'code' => '2100',
                'name' => 'Credit Card Payable',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'is_system' => true
            ],
            [
                'code' => '2200',
                'name' => 'Accrued Expenses',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'description' => 'Expenses incurred but not yet paid',
                'is_system' => true
            ],
            [
                'code' => '2250',
                'name' => 'Salaries Payable',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'is_system' => true
            ],
            [
                'code' => '2300',
                'name' => 'Sales Tax Payable',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'description' => 'Sales tax collected from customers',
                'is_system' => true
            ],
            [
                'code' => '2400',
                'name' => 'Income Tax Payable',
                'type' => 'liability',
                'subtype' => 'current_liability',
                'is_system' => true
            ],
            [
                'code' => '2500',
                'name' => 'Long-term Debt',
                'type' => 'liability',
                'subtype' => 'long_term_liability',
                'description' => 'Loans and notes payable',
                'is_system' => true
            ],

            // EQUITY (3000-3999)
            [
                'code' => '3000',
                'name' => 'Owner\'s Equity',
                'type' => 'equity',
                'subtype' => 'equity',
                'description' => 'Owner investment in business',
                'is_system' => true
            ],
            [
                'code' => '3100',
                'name' => 'Retained Earnings',
                'type' => 'equity',
                'subtype' => 'equity',
                'description' => 'Accumulated profits',
                'is_system' => true
            ],
            [
                'code' => '3200',
                'name' => 'Owner\'s Draw',
                'type' => 'equity',
                'subtype' => 'equity',
                'description' => 'Owner withdrawals',
                'is_system' => true
            ],
            [
                'code' => '3900',
                'name' => 'Income Summary',
                'type' => 'equity',
                'subtype' => 'equity',
                'description' => 'Temporary account for closing entries',
                'is_system' => true
            ],

            // REVENUE (4000-4999)
            [
                'code' => '4000',
                'name' => 'Sales Revenue',
                'type' => 'revenue',
                'subtype' => 'operating_revenue',
                'description' => 'Revenue from product sales',
                'is_system' => true
            ],
            [
                'code' => '4100',
                'name' => 'Service Revenue',
                'type' => 'revenue',
                'subtype' => 'operating_revenue',
                'description' => 'Revenue from services',
                'is_system' => true
            ],
            [
                'code' => '4200',
                'name' => 'Consulting Revenue',
                'type' => 'revenue',
                'subtype' => 'operating_revenue',
                'is_system' => true
            ],
            [
                'code' => '4900',
                'name' => 'Other Income',
                'type' => 'revenue',
                'subtype' => 'non_operating_revenue',
                'description' => 'Non-operating income',
                'is_system' => true
            ],
            [
                'code' => '4910',
                'name' => 'Interest Income',
                'type' => 'revenue',
                'subtype' => 'non_operating_revenue',
                'is_system' => true
            ],

            // EXPENSES (5000-6999)
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold',
                'type' => 'expense',
                'subtype' => 'cost_of_goods_sold',
                'description' => 'Direct costs of products sold',
                'is_system' => true
            ],
            [
                'code' => '6000',
                'name' => 'Salaries & Wages',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'description' => 'Employee compensation',
                'is_system' => true
            ],
            [
                'code' => '6100',
                'name' => 'Rent Expense',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6200',
                'name' => 'Utilities',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'description' => 'Electric, water, gas, internet',
                'is_system' => true
            ],
            [
                'code' => '6300',
                'name' => 'Office Supplies',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6400',
                'name' => 'Insurance',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6500',
                'name' => 'Marketing & Advertising',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6600',
                'name' => 'Depreciation Expense',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6700',
                'name' => 'Interest Expense',
                'type' => 'expense',
                'subtype' => 'non_operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6800',
                'name' => 'Bank Fees',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
            [
                'code' => '6900',
                'name' => 'Professional Fees',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'description' => 'Legal, accounting, consulting',
                'is_system' => true
            ],
            [
                'code' => '6950',
                'name' => 'Travel & Entertainment',
                'type' => 'expense',
                'subtype' => 'operating_expense',
                'is_system' => true
            ],
        ];

        foreach ($accounts as $accountData) {
            Account::firstOrCreate(
                ['code' => $accountData['code']],
                $accountData
            );
        }
    }

    protected function seedFiscalYears()
    {
        $currentYear = now()->year;
        
        for ($i = -1; $i <= 1; $i++) {
            $year = $currentYear + $i;
            $fiscalYear = FiscalYear::firstOrCreate(
                ['name' => "FY $year"],
                [
                    'start_date' => Carbon::create($year, 1, 1),
                    'end_date' => Carbon::create($year, 12, 31),
                    'is_current' => $i === 0,
                    'is_closed' => $i < 0,
                ]
            );

            // Create monthly periods
            for ($month = 1; $month <= 12; $month++) {
                $startDate = Carbon::create($year, $month, 1);
                $endDate = $startDate->copy()->endOfMonth();

                FiscalPeriod::firstOrCreate(
                    [
                        'fiscal_year_id' => $fiscalYear->id,
                        'name' => $startDate->format('F Y')
                    ],
                    [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'is_closed' => $i < 0 || ($i === 0 && $month < now()->month),
                    ]
                );
            }
        }
    }

    protected function seedCustomers()
    {
        $customers = [
            [
                'code' => 'CUST001',
                'name' => 'Acme Corporation',
                'email' => 'billing@acmecorp.com',
                'phone' => '+1-555-0100',
                'address' => '123 Business St',
                'city' => 'New York',
                'country' => 'USA',
                'tax_id' => '12-3456789',
                'credit_limit' => 50000,
                'payment_terms' => 30,
            ],
            [
                'code' => 'CUST002',
                'name' => 'Global Industries Ltd',
                'email' => 'accounts@globalindustries.com',
                'phone' => '+1-555-0200',
                'address' => '456 Commerce Ave',
                'city' => 'Los Angeles',
                'country' => 'USA',
                'tax_id' => '98-7654321',
                'credit_limit' => 75000,
                'payment_terms' => 60,
            ],
            [
                'code' => 'CUST003',
                'name' => 'Tech Solutions Inc',
                'email' => 'payables@techsolutions.com',
                'phone' => '+1-555-0300',
                'address' => '789 Innovation Blvd',
                'city' => 'San Francisco',
                'country' => 'USA',
                'credit_limit' => 100000,
                'payment_terms' => 45,
            ],
        ];

        foreach ($customers as $customerData) {
            Customer::firstOrCreate(
                ['code' => $customerData['code']],
                $customerData
            );
        }
    }

    protected function seedVendors()
    {
        $vendors = [
            [
                'code' => 'VEND001',
                'name' => 'Office Supplies Co',
                'email' => 'sales@officesupplies.com',
                'phone' => '+1-555-1100',
                'address' => '321 Supply St',
                'city' => 'Chicago',
                'country' => 'USA',
                'tax_id' => '11-2233445',
                'payment_terms' => 30,
            ],
            [
                'code' => 'VEND002',
                'name' => 'Utilities Provider',
                'email' => 'billing@utilities.com',
                'phone' => '+1-555-1200',
                'address' => '654 Energy Ave',
                'city' => 'Houston',
                'country' => 'USA',
                'payment_terms' => 15,
            ],
            [
                'code' => 'VEND003',
                'name' => 'Equipment Rentals LLC',
                'email' => 'rental@equipment.com',
                'phone' => '+1-555-1300',
                'address' => '987 Rental Rd',
                'city' => 'Dallas',
                'country' => 'USA',
                'payment_terms' => 30,
            ],
        ];

        foreach ($vendors as $vendorData) {
            Vendor::firstOrCreate(
                ['code' => $vendorData['code']],
                $vendorData
            );
        }
    }

    protected function seedTaxRates()
    {
        $taxRates = [
            [
                'name' => 'Standard Sales Tax',
                'code' => 'STD',
                'rate' => 8.50,
                'is_compound' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Reduced Rate',
                'code' => 'REDUCED',
                'rate' => 5.00,
                'is_compound' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Zero Rated',
                'code' => 'ZERO',
                'rate' => 0.00,
                'is_compound' => false,
                'is_active' => true,
            ],
            [
                'name' => 'VAT Standard',
                'code' => 'VAT',
                'rate' => 20.00,
                'is_compound' => false,
                'is_active' => true,
            ],
        ];

        foreach ($taxRates as $taxData) {
            TaxRate::firstOrCreate(
                ['code' => $taxData['code']],
                $taxData
            );
        }
    }
}