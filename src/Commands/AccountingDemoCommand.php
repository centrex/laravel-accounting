<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Commands;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Models\{Account, Customer, Employee, JournalEntry, Vendor};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Comprehensive multi-entity accounting demo.
 *
 * Covers: capital injection, fixed assets (3 classes), straight-line depreciation,
 * prepaid insurance amortisation, payroll (basic + HRA + transport + medical +
 * commission + bonus + employer PF + TDS), intercompany transfer pricing,
 * marketing (digital / events / research), reconciliation adjustments, VAT,
 * loan interest, miscellaneous expense heads — across 3 months under a holding
 * company with Retail, Services and Manufacturing business units.
 *
 * Ends with: Trial Balance · Balance Sheet (verified balanced) ·
 * Income Statement · 3-month head-wise forward Cash Flow Projection.
 */
final class AccountingDemoCommand extends Command
{
    protected $signature = 'accounting:demo
        {--fresh : Wipe all DEMO-prefixed journal entries before re-seeding}
        {--base-month= : Override the starting month (YYYY-MM). Default: 3 months ago.}';

    protected $description = 'Seed a comprehensive multi-entity demo with depreciation, payroll, transfer pricing, prepayments, reconciliation, and a 3-month head-wise cash flow projection.';

    /** @var array<string, Account> */
    private array $accCache = [];

    private int $jeSeq = 0;

    public function handle(Accounting $acct): int
    {
        $baseMonth = $this->option('base-month')
            ? Carbon::parse($this->option('base-month') . '-01')->startOfMonth()
            : now()->startOfMonth()->subMonths(2);

        $m1 = $baseMonth->copy();
        $m2 = $m1->copy()->addMonth();
        $m3 = $m2->copy()->addMonth();

        /* ── Fresh wipe ─────────────────────────────────────────────────────── */
        if ($this->option('fresh')) {
            $this->warn('Wiping DEMO journal entries…');
            JournalEntry::where('entry_number', 'like', 'DEMO-JE-%')->forceDelete();
        }

        /* ── Seed guard ─────────────────────────────────────────────────────── */
        $alreadySeeded = JournalEntry::where('entry_number', 'DEMO-JE-001')->exists();

        if (!$alreadySeeded) {
            $this->printBanner();

            $this->section('1. Chart of Accounts');
            $acct->initializeChartOfAccounts();
            $this->seedExtendedAccounts();
            $this->info('  Extended accounts created.');

            $this->section('2. Entities (Customers · Vendors · Employees)');
            $this->seedEntities();
            $this->info('  Entities created.');

            $this->section("3. Month 1 — {$m1->format('F Y')}");
            $this->seedMonth1($acct, $m1);

            $this->section("4. Month 2 — {$m2->format('F Y')}");
            $this->seedMonth2($acct, $m2);

            $this->section("5. Month 3 — {$m3->format('F Y')}");
            $this->seedMonth3($acct, $m3);
        } else {
            $this->warn('Demo data already exists. Use --fresh to re-seed. Showing reports only.');
        }

        /* ── Reports ─────────────────────────────────────────────────────────── */
        $periodStart = $m1->toDateString();
        $periodEnd = $m3->copy()->endOfMonth()->toDateString();

        $this->section('Trial Balance');
        $this->renderTrialBalance($acct, $periodStart, $periodEnd);

        $this->section('Balance Sheet');
        $this->renderBalanceSheet($acct, $periodEnd);

        $this->section('Income Statement');
        $this->renderIncomeStatement($acct, $periodStart, $periodEnd);

        $this->section('3-Month Head-wise Cash Flow Projection');
        $this->renderCashFlowProjection($acct, $m3->copy()->addMonth());

        return self::SUCCESS;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * CHART OF ACCOUNTS — EXTENDED
     * ═══════════════════════════════════════════════════════════════════════ */

    private function seedExtendedAccounts(): void
    {
        $accounts = [
            /* ── Assets ──────────────────────────────────────────────────── */
            ['code' => '1110', 'name' => 'Bank – Retail Unit',               'type' => 'asset',     'subtype' => 'checking_account'],
            ['code' => '1120', 'name' => 'Bank – Services Unit',             'type' => 'asset',     'subtype' => 'checking_account'],
            ['code' => '1130', 'name' => 'Bank – Manufacturing Unit',        'type' => 'asset',     'subtype' => 'checking_account'],
            ['code' => '1400', 'name' => 'Intercompany Receivable',          'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1510', 'name' => 'Prepaid Insurance',                'type' => 'asset',     'subtype' => 'prepaid_expenses'],
            ['code' => '1520', 'name' => 'Prepaid Rent',                     'type' => 'asset',     'subtype' => 'prepaid_expenses'],
            ['code' => '1600', 'name' => 'Investment in Subsidiaries',       'type' => 'asset',     'subtype' => 'investment_account'],
            /* Fixed-asset sub-accounts */
            ['code' => '1710', 'name' => 'Computer Equipment',               'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '1720', 'name' => 'Furniture & Fixtures',             'type' => 'asset',     'subtype' => 'fixed_asset'],
            ['code' => '1730', 'name' => 'Motor Vehicles',                   'type' => 'asset',     'subtype' => 'fixed_asset'],
            /* Accumulated Depreciation (contra-asset — credit-normal) */
            ['code' => '1810', 'name' => 'Accum. Depr. – Computer Equip.',  'type' => 'asset',     'subtype' => 'contra_account'],
            ['code' => '1820', 'name' => 'Accum. Depr. – Furniture',        'type' => 'asset',     'subtype' => 'contra_account'],
            ['code' => '1830', 'name' => 'Accum. Depr. – Vehicles',         'type' => 'asset',     'subtype' => 'contra_account'],
            /* ── Liabilities ─────────────────────────────────────────────── */
            ['code' => '2260', 'name' => 'Provident Fund Payable',           'type' => 'liability', 'subtype' => 'salaries_payable'],
            ['code' => '2410', 'name' => 'Intercompany Payable',             'type' => 'liability', 'subtype' => 'current_liability'],
            /* ── Revenue ─────────────────────────────────────────────────── */
            ['code' => '4200', 'name' => 'Management Fee Revenue',           'type' => 'revenue',   'subtype' => 'non_operating_revenue'],
            ['code' => '4300', 'name' => 'Manufacturing Revenue',            'type' => 'revenue',   'subtype' => 'operating_revenue'],
            /* ── Payroll expense heads ───────────────────────────────────── */
            ['code' => '6010', 'name' => 'Housing Allowance',                'type' => 'expense',   'subtype' => 'employee_benefits_expense'],
            ['code' => '6020', 'name' => 'Transport Allowance',              'type' => 'expense',   'subtype' => 'employee_benefits_expense'],
            ['code' => '6030', 'name' => 'Medical Allowance',                'type' => 'expense',   'subtype' => 'employee_benefits_expense'],
            ['code' => '6040', 'name' => 'Sales Commission',                 'type' => 'expense',   'subtype' => 'selling_expense'],
            ['code' => '6050', 'name' => 'Employer PF Contribution',         'type' => 'expense',   'subtype' => 'employee_benefits_expense'],
            ['code' => '6060', 'name' => 'Staff Bonus',                      'type' => 'expense',   'subtype' => 'employee_benefits_expense'],
            /* ── Operating expense heads ─────────────────────────────────── */
            ['code' => '6300', 'name' => 'Office Supplies',                  'type' => 'expense',   'subtype' => 'office_supplies_expense'],
            ['code' => '6400', 'name' => 'Insurance Expense',                'type' => 'expense',   'subtype' => 'insurance_expense'],
            /* ── Marketing heads ─────────────────────────────────────────── */
            ['code' => '6500', 'name' => 'Marketing & Advertising',          'type' => 'expense',   'subtype' => 'marketing_expense'],
            ['code' => '6510', 'name' => 'Digital Marketing',                'type' => 'expense',   'subtype' => 'advertising_expense'],
            ['code' => '6520', 'name' => 'Events & Promotions',              'type' => 'expense',   'subtype' => 'advertising_expense'],
            ['code' => '6530', 'name' => 'Market Research',                  'type' => 'expense',   'subtype' => 'marketing_expense'],
            /* ── Miscellaneous expense heads ─────────────────────────────── */
            ['code' => '6900', 'name' => 'Miscellaneous Expense',            'type' => 'expense',   'subtype' => 'other_expense'],
            ['code' => '6910', 'name' => 'Staff Entertainment',              'type' => 'expense',   'subtype' => 'food_and_entertainment_expense'],
            ['code' => '6920', 'name' => 'Travel & Conveyance',              'type' => 'expense',   'subtype' => 'travel_expense'],
            ['code' => '6930', 'name' => 'Printing & Stationery',            'type' => 'expense',   'subtype' => 'office_supplies_expense'],
            ['code' => '6940', 'name' => 'Cleaning & Maintenance',           'type' => 'expense',   'subtype' => 'maintenance_and_repair_expense'],
            /* ── Transfer pricing ────────────────────────────────────────── */
            ['code' => '7000', 'name' => 'Management Fee Expense',           'type' => 'expense',   'subtype' => 'consulting_expense'],
        ];

        foreach ($accounts as $data) {
            Account::firstOrCreate(['code' => $data['code']], array_merge($data, ['is_system' => true]));
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * ENTITIES
     * ═══════════════════════════════════════════════════════════════════════ */

    private function seedEntities(): void
    {
        foreach ([
            ['code' => 'SUB-RETAIL', 'name' => 'Centrex Retail Ltd',        'email' => 'ar@retail.centrex.com',    'payment_terms' => 30],
            ['code' => 'SUB-SVC',    'name' => 'Centrex Services Ltd',       'email' => 'ar@services.centrex.com',  'payment_terms' => 30],
            ['code' => 'SUB-MFG',    'name' => 'Centrex Manufacturing Ltd',  'email' => 'ar@mfg.centrex.com',       'payment_terms' => 30],
            ['code' => 'EXT-DCG',    'name' => 'Dhaka Commerce Group',       'email' => 'billing@dhakacommerce.bd', 'payment_terms' => 45],
            ['code' => 'EXT-SIL',    'name' => 'Sylhet Industries Ltd',      'email' => 'finance@sylhetind.com',    'payment_terms' => 60],
        ] as $c) {
            Customer::firstOrCreate(['code' => $c['code']], $c);
        }

        foreach ([
            ['code' => 'VND-TECH', 'name' => 'Tech Supplies Bangladesh', 'email' => 'orders@techsup.bd', 'payment_terms' => 30],
            ['code' => 'VND-RAW',  'name' => 'Raw Materials Corp BD',    'email' => 'sales@rawmat.bd',   'payment_terms' => 45],
            ['code' => 'VND-OFFC', 'name' => 'Office Solutions BD',      'email' => 'billing@offbd.com', 'payment_terms' => 15],
        ] as $v) {
            Vendor::firstOrCreate(['code' => $v['code']], $v);
        }

        foreach ([
            ['code' => 'EMP-001', 'name' => 'Rahul Ahmed',   'email' => 'rahul@centrex.com'],
            ['code' => 'EMP-002', 'name' => 'Priya Sharma',  'email' => 'priya@centrex.com'],
            ['code' => 'EMP-003', 'name' => 'Karim Hassan',  'email' => 'karim@centrex.com'],
            ['code' => 'EMP-004', 'name' => 'Nadia Islam',   'email' => 'nadia@centrex.com'],
            ['code' => 'EMP-005', 'name' => 'Tarek Hossain', 'email' => 'tarek@centrex.com'],
        ] as $e) {
            Employee::firstOrCreate(['code' => $e['code']], $e);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * JE HELPER — creates & posts in one call
     * ═══════════════════════════════════════════════════════════════════════ */

    /** @param list<array<string, mixed>> $lines */
    private function je(Accounting $acct, string $desc, string $date, array $lines, string $type = 'general'): JournalEntry
    {
        $this->jeSeq++;
        $num = sprintf('DEMO-JE-%03d', $this->jeSeq);
        $entry = $acct->createJournalEntry([
            'entry_number' => $num,
            'date'         => $date,
            'type'         => $type,
            'description'  => $desc,
            'lines'        => $lines,
        ]);
        $entry->post();
        $this->line(sprintf('    [%s] %s', $num, mb_strimwidth($desc, 0, 65, '…')));

        return $entry;
    }

    /** Lookup account ID by code (cached). */
    private function acc(string $code): int
    {
        if (!isset($this->accCache[$code])) {
            $this->accCache[$code] = Account::where('code', $code)->where('is_active', true)->firstOrFail();
        }

        return $this->accCache[$code]->id;
    }

    /** @return array<string, mixed> */
    private function dr(string $code, float $amount, string $desc = ''): array
    {
        return ['account_id' => $this->acc($code), 'type' => 'debit', 'amount' => $amount, 'description' => $desc];
    }

    /** @return array<string, mixed> */
    private function cr(string $code, float $amount, string $desc = ''): array
    {
        return ['account_id' => $this->acc($code), 'type' => 'credit', 'amount' => $amount, 'description' => $desc];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * MONTH 1 TRANSACTIONS
     *
     * Scenarios: Capital injection, bank loan, subsidiary investment,
     * asset purchases (equipment / furniture / vehicles),
     * prepaid insurance, inventory, sales across 3 BUs,
     * payroll (basic + HRA + transport + medical + commission + bonus + employer PF + TDS),
     * transfer pricing, marketing, operating & miscellaneous expenses,
     * AP settlement, VAT remittance.
     * ═══════════════════════════════════════════════════════════════════════ */

    private function seedMonth1(Accounting $acct, Carbon $m): void
    {
        $d = fn (int $day): string => $m->copy()->day($day)->toDateString();

        /* ── Capital & financing ─────────────────────────────────────────── */

        // JE-001 Capital injection — Centrex Holdings Ltd
        $this->je($acct, 'Capital injection – Centrex Holdings Ltd (opening equity)', $d(1), [
            $this->dr('1100', 50_000_000, 'Opening bank deposit'),
            $this->dr('1000', 2_000_000, 'Petty cash float'),
            $this->cr('3000', 52_000_000, 'Share capital – founding shareholders'),
        ]);

        // JE-002 5-year term loan @ 12% p.a.
        $this->je($acct, 'Bank term loan – 5yr @ 12% p.a.', $d(2), [
            $this->dr('1100', 15_000_000, 'Loan proceeds credited to bank'),
            $this->cr('2500', 15_000_000, 'Long-term loan payable'),
        ]);

        // JE-003 Capital deployed to subsidiary units
        $this->je($acct, 'Capital deployed to 3 subsidiary business units', $d(3), [
            $this->dr('1600', 25_000_000, 'Investment in subsidiaries'),
            $this->cr('1100', 25_000_000, 'Bank transfer to BU accounts'),
        ]);

        /* ── Fixed asset purchases ───────────────────────────────────────── */

        // JE-004 Computer equipment (3yr SL, no residual)
        $this->je($acct, 'Computer equipment – 30 servers + 50 workstations', $d(5), [
            $this->dr('1710', 3_000_000, 'Computer equipment at cost'),
            $this->cr('1100', 3_000_000, 'Bank'),
        ]);

        // JE-005 Furniture & fixtures (5yr SL)
        $this->je($acct, 'Office furniture & fixtures – 3 floors', $d(5), [
            $this->dr('1720', 1_500_000, 'Furniture & fixtures at cost'),
            $this->cr('1100', 1_500_000, 'Bank'),
        ]);

        // JE-006 Motor vehicles (4yr SL)
        $this->je($acct, 'Motor vehicles – 3 delivery vans', $d(7), [
            $this->dr('1730', 4_800_000, 'Motor vehicles at cost'),
            $this->cr('1100', 4_800_000, 'Bank'),
        ]);

        /* ── Prepayments ─────────────────────────────────────────────────── */

        // JE-007 Prepaid insurance (3-month commercial policy, ৳100k/month)
        $this->je($acct, 'Prepaid insurance – 3-month commercial policy (৳100k/mo)', $d(8), [
            $this->dr('1510', 300_000, 'Prepaid insurance – 3 months'),
            $this->cr('1100', 300_000, 'Bank'),
        ]);

        /* ── Inventory ───────────────────────────────────────────────────── */

        // JE-008 Inventory purchase on credit
        $this->je($acct, 'Inventory purchase – raw materials & finished goods (credit)', $d(10), [
            $this->dr('1300', 5_000_000, 'Inventory stock'),
            $this->cr('2000', 5_000_000, 'AP – Raw Materials Corp BD'),
        ]);

        /* ── Revenue ─────────────────────────────────────────────────────── */

        // JE-009 Retail unit sales invoices (VAT 15%)
        $this->je($acct, 'Sales invoices – Retail BU (Dhaka Commerce + Sylhet Industries)', $d(15), [
            $this->dr('1200', 6_900_000, 'AR – Retail customers'),
            $this->cr('4000', 6_000_000, 'Sales revenue'),
            $this->cr('2300', 900_000, 'VAT 15%'),
        ]);

        // JE-010 Services unit invoices
        $this->je($acct, 'Service invoices – Services BU (IT consulting + SLA projects)', $d(15), [
            $this->dr('1200', 3_450_000, 'AR – Service clients'),
            $this->cr('4100', 3_000_000, 'Service revenue'),
            $this->cr('2300', 450_000, 'VAT 15%'),
        ]);

        // JE-011 Manufacturing unit sales
        $this->je($acct, 'Manufacturing sales – contract production orders batch 1', $d(18), [
            $this->dr('1200', 2_300_000, 'AR – Manufacturing clients'),
            $this->cr('4300', 2_000_000, 'Manufacturing revenue'),
            $this->cr('2300', 300_000, 'VAT 15%'),
        ]);

        // JE-012 Customer collections
        $this->je($acct, 'Customer collections – partial AR recovery M1', $d(20), [
            $this->dr('1100', 10_350_000, 'Bank receipt'),
            $this->cr('1200', 10_350_000, 'AR cleared'),
        ]);

        // JE-013 COGS
        $this->je($acct, 'COGS – M1 inventory consumed across all BUs', $d(20), [
            $this->dr('5000', 3_800_000, 'Cost of goods sold'),
            $this->cr('1300', 3_800_000, 'Inventory'),
        ]);

        /* ── Transfer pricing (parent → subsidiaries management fee) ──────── */
        // Parent bills subsidiaries; subsidiary expense entry shows intercompany nature.
        // In consolidated view these net to zero — demonstrating TP concept.

        // JE-014 Management fee billed to BUs (parent revenue)
        $this->je($acct, 'Transfer pricing – mgmt fee billed to 3 business units', $d(22), [
            $this->dr('1100', 1_500_000, 'Management fee received from BUs'),
            $this->cr('4200', 1_500_000, 'Management fee revenue'),
        ]);

        // JE-015 BU counterpart: management fee expense (consolidation entry)
        $this->je($acct, 'Transfer pricing – BU management fee expense (interco counterpart)', $d(22), [
            $this->dr('7000', 1_500_000, 'Management fee expense – charged by parent'),
            $this->cr('1100', 1_500_000, 'Bank – fee settlement'),
        ]);

        /* ── Payroll M1 ──────────────────────────────────────────────────────
         * Gross: Basic 2,500,000 + HRA 500,000 + Transport 250,000 +
         *        Medical 125,000 + Commission 180,000 + Bonus 100,000 = 3,655,000
         * Employee deductions: PF 125,000 (5% of basic) + Tax 365,500 (10%)
         * Net pay: 3,655,000 − 125,000 − 365,500 = 3,164,500
         * Employer PF (5% of basic): 125,000
         * Total payroll expense: 3,655,000 + 125,000 = 3,780,000
         * ────────────────────────────────────────────────────────────────────
         * DR side: 2,500,000 + 500,000 + 250,000 + 125,000 + 180,000 + 100,000 + 125,000 = 3,780,000
         * CR side: 3,164,500 + 365,500 + 250,000 = 3,780,000  ✓
         * ─────────────────────────────────────────────────────────────────── */

        // JE-016 Payroll accrual
        $this->je($acct, 'Payroll accrual – M1 (25 staff: salary + all allowances + commission + bonus)', $d(28), [
            $this->dr('6000', 2_500_000, 'Basic salary – 25 staff'),
            $this->dr('6010', 500_000, 'Housing allowance'),
            $this->dr('6020', 250_000, 'Transport allowance'),
            $this->dr('6030', 125_000, 'Medical allowance'),
            $this->dr('6040', 180_000, 'Sales commission – Retail team'),
            $this->dr('6060', 100_000, 'Q1 joining bonus – 5 new hires'),
            $this->dr('6050', 125_000, 'Employer PF contribution 5%'),
            $this->cr('2250', 3_164_500, 'Net salaries payable'),
            $this->cr('2400', 365_500, 'Employee income tax payable (TDS)'),
            $this->cr('2260', 250_000, 'PF payable (employee 125k + employer 125k)'),
        ]);

        // JE-017 Net salary disbursement
        $this->je($acct, 'Net salary payment – M1 bank transfer', $d(29), [
            $this->dr('2250', 3_164_500, 'Salaries payable cleared'),
            $this->cr('1100', 3_164_500, 'Bank'),
        ]);

        // JE-018 TDS + PF remittance to government
        $this->je($acct, 'TDS & PF remittance to NBR / RJSC – M1', $d(30), [
            $this->dr('2400', 365_500, 'Income tax payable cleared'),
            $this->dr('2260', 250_000, 'PF payable cleared'),
            $this->cr('1100', 615_500, 'Bank'),
        ]);

        /* ── Marketing ───────────────────────────────────────────────────── */

        // JE-019 Marketing campaigns
        $this->je($acct, 'Marketing – digital campaigns, trade show, market research M1', $d(15), [
            $this->dr('6510', 350_000, 'Digital marketing: SEO, PPC, social'),
            $this->dr('6520', 200_000, 'Trade show booth & collateral'),
            $this->dr('6530', 100_000, 'Market research: consumer survey'),
            $this->cr('1100', 650_000, 'Bank'),
        ]);

        /* ── Operating expenses ──────────────────────────────────────────── */

        // JE-020 Rent + utilities + office supplies
        $this->je($acct, 'Operating expenses – rent (3 offices), utilities, office supplies M1', $d(15), [
            $this->dr('6100', 300_000, 'Rent – HO + 2 branch offices'),
            $this->dr('6200', 80_000, 'Electricity, water, gas'),
            $this->dr('6300', 45_000, 'Office supplies & stationery stock'),
            $this->cr('1100', 425_000, 'Bank'),
        ]);

        /* ── Miscellaneous expenses ──────────────────────────────────────── */

        // JE-021 Misc heads
        $this->je($acct, 'Miscellaneous expenses – M1 (entertainment, travel, printing, cleaning)', $d(20), [
            $this->dr('6910', 50_000, 'Team lunch & client refreshments'),
            $this->dr('6920', 75_000, 'Client visit travel & conveyance'),
            $this->dr('6930', 30_000, 'Business cards & brochure printing'),
            $this->dr('6940', 25_000, 'Monthly cleaning service'),
            $this->cr('1100', 180_000, 'Bank'),
        ]);

        /* ── AP & VAT settlement ─────────────────────────────────────────── */

        // JE-022 Partial inventory AP payment
        $this->je($acct, 'AP partial payment – inventory vendor (Raw Materials Corp BD)', $d(25), [
            $this->dr('2000', 3_000_000, 'AP cleared – partial'),
            $this->cr('1100', 3_000_000, 'Bank'),
        ]);

        // JE-023 VAT remittance to NBR
        $this->je($acct, 'VAT remittance to NBR – M1 (Sales + Service + Mfg. VAT)', $d(30), [
            $this->dr('2300', 1_650_000, 'VAT payable cleared'),
            $this->cr('1100', 1_650_000, 'Bank'),
        ]);

        $this->info(sprintf('  ✓ %d journal entries posted for %s.', $this->jeSeq, $m->format('F Y')));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * MONTH 2 TRANSACTIONS
     *
     * New scenarios: Straight-line depreciation (1st month), prepaid
     * insurance amortisation (month 1 of 3), bank reconciliation
     * (charges discovered + interest income), repeat revenue / payroll /
     * marketing / misc, additional AP & VAT settlements.
     * ═══════════════════════════════════════════════════════════════════════ */

    private function seedMonth2(Accounting $acct, Carbon $m): void
    {
        $d = fn (int $day): string => $m->copy()->day($day)->toDateString();

        /* ── Depreciation (SL — month 1 of asset lives) ──────────────────── */
        // Computer 1710: ৳3,000,000 / 36 months = ৳83,333/mo
        // Furniture 1720: ৳1,500,000 / 60 months = ৳25,000/mo
        // Vehicles  1730: ৳4,800,000 / 48 months = ৳100,000/mo
        // Total DR 6600 = 208,333 = CR 1810+1820+1830  ✓

        // JE-024 Depreciation entry
        $this->je($acct, 'Depreciation – M2 (Computer 3yr SL, Furniture 5yr SL, Vehicles 4yr SL)', $d(1), [
            $this->dr('6600', 208_333, 'Depreciation – 3 asset classes'),
            $this->cr('1810', 83_333, 'Accum. depr. – Computer equip. (3yr SL)'),
            $this->cr('1820', 25_000, 'Accum. depr. – Furniture (5yr SL)'),
            $this->cr('1830', 100_000, 'Accum. depr. – Vehicles (4yr SL)'),
        ]);

        /* ── Prepaid insurance amortisation (month 1 of 3) ───────────────── */

        // JE-025 Insurance amortisation
        $this->je($acct, 'Prepaid insurance amortisation – month 1 of 3 (৳100k)', $d(1), [
            $this->dr('6400', 100_000, 'Insurance expense – current month'),
            $this->cr('1510', 100_000, 'Prepaid insurance asset reduced'),
        ]);

        /* ── Inventory replenishment ─────────────────────────────────────── */

        // JE-026 Inventory M2
        $this->je($acct, 'Inventory replenishment – M2 (credit purchase)', $d(5), [
            $this->dr('1300', 5_000_000, 'Inventory'),
            $this->cr('2000', 5_000_000, 'AP – Raw Materials Corp BD'),
        ]);

        /* ── Revenue M2 ──────────────────────────────────────────────────── */

        // JE-027 Retail sales M2 (+10% growth)
        $this->je($acct, 'Sales invoices – Retail BU M2 (+10% growth)', $d(15), [
            $this->dr('1200', 7_590_000, 'AR'),
            $this->cr('4000', 6_600_000, 'Sales revenue'),
            $this->cr('2300', 990_000, 'VAT 15%'),
        ]);

        // JE-028 Services M2
        $this->je($acct, 'Service invoices – Services BU M2', $d(15), [
            $this->dr('1200', 2_875_000, 'AR'),
            $this->cr('4100', 2_500_000, 'Service revenue'),
            $this->cr('2300', 375_000, 'VAT 15%'),
        ]);

        // JE-029 Manufacturing M2
        $this->je($acct, 'Manufacturing sales – M2 production batch', $d(18), [
            $this->dr('1200', 2_645_000, 'AR'),
            $this->cr('4300', 2_300_000, 'Manufacturing revenue'),
            $this->cr('2300', 345_000, 'VAT 15%'),
        ]);

        // JE-030 Collections M2
        $this->je($acct, 'Customer collections – M2', $d(22), [
            $this->dr('1100', 11_500_000, 'Bank'),
            $this->cr('1200', 11_500_000, 'AR cleared'),
        ]);

        // JE-031 COGS M2
        $this->je($acct, 'COGS – M2 inventory consumed', $d(22), [
            $this->dr('5000', 4_200_000, 'COGS'),
            $this->cr('1300', 4_200_000, 'Inventory'),
        ]);

        /* ── Transfer pricing M2 ─────────────────────────────────────────── */

        // JE-032 Management fee M2
        $this->je($acct, 'Transfer pricing – mgmt fee collected M2', $d(22), [
            $this->dr('1100', 1_500_000, 'Bank'),
            $this->cr('4200', 1_500_000, 'Management fee revenue'),
        ]);

        // JE-033 BU counterpart M2
        $this->je($acct, 'Transfer pricing – BU management fee expense M2 (interco)', $d(22), [
            $this->dr('7000', 1_500_000, 'Management fee expense'),
            $this->cr('1100', 1_500_000, 'Bank'),
        ]);

        /* ── Payroll M2 ───────────────────────────────────────────────────────
         * Gross: Basic 2,500,000 + HRA 500,000 + Transport 250,000 +
         *        Medical 125,000 + Commission 210,000 = 3,585,000 (no bonus)
         * Employee PF: 125,000 | TDS: 346,000 | Net pay: 3,114,000
         * Employer PF: 125,000
         * DR: 2,500,000+500,000+250,000+125,000+210,000+125,000 = 3,710,000
         * CR: 3,114,000+346,000+250,000 = 3,710,000  ✓
         * ─────────────────────────────────────────────────────────────────── */

        // JE-034 Payroll accrual M2
        $this->je($acct, 'Payroll accrual – M2 (no bonus; higher commission on M2 deals)', $d(28), [
            $this->dr('6000', 2_500_000, 'Basic salary'),
            $this->dr('6010', 500_000, 'Housing allowance'),
            $this->dr('6020', 250_000, 'Transport allowance'),
            $this->dr('6030', 125_000, 'Medical allowance'),
            $this->dr('6040', 210_000, 'Sales commission – M2 deals'),
            $this->dr('6050', 125_000, 'Employer PF'),
            $this->cr('2250', 3_114_000, 'Net salaries payable'),
            $this->cr('2400', 346_000, 'Income tax payable'),
            $this->cr('2260', 250_000, 'PF payable'),
        ]);

        // JE-035 Net salary M2
        $this->je($acct, 'Net salary payment – M2', $d(28), [
            $this->dr('2250', 3_114_000, 'Salaries payable cleared'),
            $this->cr('1100', 3_114_000, 'Bank'),
        ]);

        // JE-036 TDS + PF M2
        $this->je($acct, 'TDS & PF remittance – M2', $d(28), [
            $this->dr('2400', 346_000, 'Income tax payable'),
            $this->dr('2260', 250_000, 'PF payable'),
            $this->cr('1100', 596_000, 'Bank'),
        ]);

        /* ── Bank reconciliation ─────────────────────────────────────────── */

        // JE-037 Bank charges discovered during reconciliation
        $this->je($acct, 'Bank reconciliation – unrecorded bank service charges discovered', $d(28), [
            $this->dr('6800', 18_500, 'Bank charges & RTGS fees'),
            $this->cr('1100', 18_500, 'Bank'),
        ]);

        // JE-038 Bank interest income not yet recorded
        $this->je($acct, 'Bank reconciliation – interest income on operating balance credited by bank', $d(28), [
            $this->dr('1100', 42_000, 'Bank'),
            $this->cr('4900', 42_000, 'Interest income on deposits'),
        ]);

        /* ── Marketing M2 ────────────────────────────────────────────────── */

        // JE-039 Marketing M2
        $this->je($acct, 'Marketing – PPC campaigns + dealer conference M2', $d(15), [
            $this->dr('6510', 400_000, 'Digital marketing – PPC & display'),
            $this->dr('6520', 150_000, 'Dealer conference sponsorship'),
            $this->cr('1100', 550_000, 'Bank'),
        ]);

        /* ── Operating expenses M2 ───────────────────────────────────────── */

        // JE-040 Operating M2
        $this->je($acct, 'Operating expenses – rent, utilities, office supplies M2', $d(15), [
            $this->dr('6100', 300_000, 'Rent'),
            $this->dr('6200', 85_000, 'Utilities'),
            $this->dr('6300', 30_000, 'Office supplies'),
            $this->cr('1100', 415_000, 'Bank'),
        ]);

        /* ── Miscellaneous M2 ────────────────────────────────────────────── */

        // JE-041 Misc M2
        $this->je($acct, 'Miscellaneous expenses – M2', $d(20), [
            $this->dr('6910', 40_000, 'Staff entertainment'),
            $this->dr('6920', 65_000, 'Travel & client meetings'),
            $this->dr('6930', 20_000, 'Printing'),
            $this->dr('6940', 20_000, 'Cleaning service'),
            $this->cr('1100', 145_000, 'Bank'),
        ]);

        /* ── AP & VAT settlement M2 ──────────────────────────────────────── */

        // JE-042 AP payment M2 (clearing remaining M1 balance ৳2M)
        $this->je($acct, 'AP payment – remaining M1 inventory bill (৳2M outstanding)', $d(20), [
            $this->dr('2000', 2_000_000, 'AP cleared – M1 balance'),
            $this->cr('1100', 2_000_000, 'Bank'),
        ]);

        // JE-043 VAT M2
        $this->je($acct, 'VAT remittance to NBR – M2', $d(28), [
            $this->dr('2300', 1_710_000, 'VAT cleared'),
            $this->cr('1100', 1_710_000, 'Bank'),
        ]);

        $this->info(sprintf('  ✓ Journal entries posted for %s.', $m->format('F Y')));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * MONTH 3 TRANSACTIONS
     *
     * New scenarios: Depreciation month 2, prepaid amortisation month 2
     * (month 3 carries ৳100k still on balance sheet), Q1 year-end larger
     * marketing budget, loan interest accrual + payment, Q1 closing bank
     * interest income.
     * ═══════════════════════════════════════════════════════════════════════ */

    private function seedMonth3(Accounting $acct, Carbon $m): void
    {
        $d = fn (int $day): string => $m->copy()->day($day)->toDateString();

        /* ── Depreciation (month 2 of asset lives) ───────────────────────── */

        // JE-044
        $this->je($acct, 'Depreciation – M3 (month 2; same SL amounts)', $d(1), [
            $this->dr('6600', 208_333, 'Depreciation'),
            $this->cr('1810', 83_333, 'Accum. depr. – Computer equip.'),
            $this->cr('1820', 25_000, 'Accum. depr. – Furniture'),
            $this->cr('1830', 100_000, 'Accum. depr. – Vehicles'),
        ]);

        /* ── Prepaid amortisation month 2 of 3 (৳100k remains after this) ── */

        // JE-045
        $this->je($acct, 'Prepaid insurance amortisation – month 2 of 3 (৳100k residual stays)', $d(1), [
            $this->dr('6400', 100_000, 'Insurance expense'),
            $this->cr('1510', 100_000, 'Prepaid insurance'),
        ]);

        /* ── Inventory replenishment M3 ──────────────────────────────────── */

        // JE-046
        $this->je($acct, 'Inventory replenishment – M3 (credit purchase)', $d(5), [
            $this->dr('1300', 5_000_000, 'Inventory'),
            $this->cr('2000', 5_000_000, 'AP – Raw Materials Corp BD'),
        ]);

        /* ── Revenue M3 ──────────────────────────────────────────────────── */

        // JE-047 Retail M3 (Q1 seasonal peak, +9% on M2)
        $this->je($acct, 'Sales invoices – Retail BU M3 (Q1 seasonal peak)', $d(15), [
            $this->dr('1200', 8_280_000, 'AR'),
            $this->cr('4000', 7_200_000, 'Sales revenue'),
            $this->cr('2300', 1_080_000, 'VAT 15%'),
        ]);

        // JE-048 Services M3
        $this->je($acct, 'Service invoices – Services BU M3 (Q1 project completions)', $d(15), [
            $this->dr('1200', 3_162_500, 'AR'),
            $this->cr('4100', 2_750_000, 'Service revenue'),
            $this->cr('2300', 412_500, 'VAT 15%'),
        ]);

        // JE-049 Manufacturing M3
        $this->je($acct, 'Manufacturing sales – M3 quarterly delivery batch', $d(18), [
            $this->dr('1200', 2_875_000, 'AR'),
            $this->cr('4300', 2_500_000, 'Manufacturing revenue'),
            $this->cr('2300', 375_000, 'VAT 15%'),
        ]);

        // JE-050 Collections M3
        $this->je($acct, 'Customer collections – M3', $d(22), [
            $this->dr('1100', 12_650_000, 'Bank'),
            $this->cr('1200', 12_650_000, 'AR cleared'),
        ]);

        // JE-051 COGS M3
        $this->je($acct, 'COGS – M3 inventory consumed', $d(22), [
            $this->dr('5000', 4_600_000, 'COGS'),
            $this->cr('1300', 4_600_000, 'Inventory'),
        ]);

        /* ── Transfer pricing M3 ─────────────────────────────────────────── */

        // JE-052
        $this->je($acct, 'Transfer pricing – mgmt fee collected M3', $d(22), [
            $this->dr('1100', 1_500_000, 'Bank'),
            $this->cr('4200', 1_500_000, 'Management fee revenue'),
        ]);

        // JE-053
        $this->je($acct, 'Transfer pricing – BU management fee expense M3 (interco)', $d(22), [
            $this->dr('7000', 1_500_000, 'Management fee expense'),
            $this->cr('1100', 1_500_000, 'Bank'),
        ]);

        /* ── Payroll M3 ───────────────────────────────────────────────────────
         * Gross: Basic 2,500,000 + HRA 500,000 + Transport 250,000 +
         *        Medical 125,000 + Commission 250,000 = 3,625,000
         * Employee PF: 125,000 | TDS: 350,000 | Net pay: 3,150,000
         * Employer PF: 125,000
         * DR: 2,500,000+500,000+250,000+125,000+250,000+125,000 = 3,750,000
         * CR: 3,150,000+350,000+250,000 = 3,750,000  ✓
         * ─────────────────────────────────────────────────────────────────── */

        // JE-054 Payroll accrual M3
        $this->je($acct, 'Payroll accrual – M3 (Q1 achievement commission uplift)', $d(28), [
            $this->dr('6000', 2_500_000, 'Basic salary'),
            $this->dr('6010', 500_000, 'Housing allowance'),
            $this->dr('6020', 250_000, 'Transport allowance'),
            $this->dr('6030', 125_000, 'Medical allowance'),
            $this->dr('6040', 250_000, 'Sales commission – Q1 achievement bonus'),
            $this->dr('6050', 125_000, 'Employer PF'),
            $this->cr('2250', 3_150_000, 'Net salaries payable'),
            $this->cr('2400', 350_000, 'Income tax payable'),
            $this->cr('2260', 250_000, 'PF payable'),
        ]);

        // JE-055 Net salary M3
        $this->je($acct, 'Net salary payment – M3', $d(28), [
            $this->dr('2250', 3_150_000, 'Salaries payable cleared'),
            $this->cr('1100', 3_150_000, 'Bank'),
        ]);

        // JE-056 TDS + PF M3
        $this->je($acct, 'TDS & PF remittance – M3', $d(28), [
            $this->dr('2400', 350_000, 'Income tax payable'),
            $this->dr('2260', 250_000, 'PF payable'),
            $this->cr('1100', 600_000, 'Bank'),
        ]);

        /* ── Marketing M3 (Q1 end campaign — larger budget) ─────────────── */

        // JE-057
        $this->je($acct, 'Marketing – Q1 year-end push: digital + annual dealer meet + research', $d(20), [
            $this->dr('6510', 500_000, 'Digital – Q1 year-end retargeting'),
            $this->dr('6520', 300_000, 'Annual dealer meet & awards night'),
            $this->dr('6530', 200_000, 'New segment market research'),
            $this->cr('1100', 1_000_000, 'Bank'),
        ]);

        /* ── Operating expenses M3 ───────────────────────────────────────── */

        // JE-058
        $this->je($acct, 'Operating expenses – rent, utilities, office supplies M3', $d(15), [
            $this->dr('6100', 300_000, 'Rent'),
            $this->dr('6200', 90_000, 'Utilities (seasonal higher)'),
            $this->dr('6300', 50_000, 'Office supplies & stationery'),
            $this->cr('1100', 440_000, 'Bank'),
        ]);

        /* ── Miscellaneous M3 ────────────────────────────────────────────── */

        // JE-059
        $this->je($acct, 'Miscellaneous expenses – M3 (Q1 close: bigger travel + staff appreciation)', $d(20), [
            $this->dr('6910', 60_000, 'Staff appreciation dinner'),
            $this->dr('6920', 80_000, 'Q1 review inter-city travel'),
            $this->dr('6930', 25_000, 'Printing – annual reports & stationery'),
            $this->dr('6940', 30_000, 'Deep clean + pest control'),
            $this->cr('1100', 195_000, 'Bank'),
        ]);

        /* ── Loan interest accrual & payment ─────────────────────────────── */
        // Interest: ৳15,000,000 × 12% ÷ 12 = ৳150,000/month

        // JE-060 Interest accrual
        $this->je($acct, 'Loan interest accrual – Q1 (৳15M × 12% ÷ 12 = ৳150k)', $d(31), [
            $this->dr('6700', 150_000, 'Interest expense'),
            $this->cr('2200', 150_000, 'Accrued interest payable'),
        ]);

        // JE-061 Interest payment
        $this->je($acct, 'Loan interest payment – Q1', $d(31), [
            $this->dr('2200', 150_000, 'Accrued interest cleared'),
            $this->cr('1100', 150_000, 'Bank'),
        ]);

        /* ── Bank interest income M3 ─────────────────────────────────────── */

        // JE-062
        $this->je($acct, 'Bank interest income – M3 (on growing operating balance)', $d(31), [
            $this->dr('1100', 55_000, 'Bank'),
            $this->cr('4900', 55_000, 'Interest income on deposits'),
        ]);

        /* ── AP & VAT settlement M3 ──────────────────────────────────────── */

        // JE-063 AP payment M3 (paying M2 inventory)
        $this->je($acct, 'AP payment – M2 inventory bill settlement (৳5M)', $d(22), [
            $this->dr('2000', 5_000_000, 'AP cleared – M2 balance'),
            $this->cr('1100', 5_000_000, 'Bank'),
        ]);

        // JE-064 VAT M3
        $this->je($acct, 'VAT remittance to NBR – M3', $d(28), [
            $this->dr('2300', 1_867_500, 'VAT cleared'),
            $this->cr('1100', 1_867_500, 'Bank'),
        ]);

        $this->info(sprintf('  ✓ Journal entries posted for %s.', $m->format('F Y')));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * REPORT RENDERERS
     * ═══════════════════════════════════════════════════════════════════════ */

    private function renderTrialBalance(Accounting $acct, string $start, string $end): void
    {
        $data = $acct->getTrialBalance($start, $end);
        $rows = [];

        foreach ($data['accounts'] as $r) {
            $rows[] = [
                $r['account']->code,
                $r['account']->name,
                $r['debit'] > 0 ? $this->f($r['debit']) : '',
                $r['credit'] > 0 ? $this->f($r['credit']) : '',
            ];
        }

        $this->table(['Code', 'Account', 'Debit (BDT)', 'Credit (BDT)'], $rows);
        $this->line('  Total Debits : ' . $this->f($data['total_debits']));
        $this->line('  Total Credits: ' . $this->f($data['total_credits']));
        $this->line('  Balanced?    : ' . ($data['is_balanced'] ? '<fg=green>YES ✓</>' : '<fg=red>NO ✗</>'));
    }

    private function renderBalanceSheet(Accounting $acct, string $date): void
    {
        $data = $acct->getBalanceSheet($date);
        $rows = [];

        foreach (['assets', 'liabilities', 'equity'] as $section) {
            $sec = $data[$section];
            $rows[] = ['<fg=yellow>' . strtoupper($section) . '</>', '', ''];

            foreach ($sec['accounts'] ?? [] as $item) {
                if (abs($item['balance']) < 0.01) {
                    continue;
                }
                $rows[] = [
                    '  ' . $item['account']->code,
                    '  ' . $item['account']->name,
                    $this->f($item['balance']),
                ];
            }

            if ($section === 'equity') {
                $ni = $sec['net_income'] ?? 0.0;

                if (abs((float) $ni) > 0.01) {
                    $rows[] = ['  ----', '  Net Income (current period)', $this->f($ni)];
                }
                $rows[] = ['', '<options=bold>  Total Equity (incl. net income)</>', $this->f(($sec['total'] ?? 0) + $ni)];
            } else {
                $rows[] = ['', '<options=bold>  Subtotal ' . ucfirst($section) . '</>', $this->f($sec['total'] ?? 0)];
            }

            $rows[] = ['', '', ''];
        }

        $this->table(['Code', 'Account', 'Balance (BDT)'], $rows);

        $assetTotal = $data['assets']['total'] ?? 0;
        $liabTotal = $data['liabilities']['total'] ?? 0;
        $equityTotal = ($data['equity']['total'] ?? 0) + ($data['equity']['net_income'] ?? 0);

        $this->line('  Assets         : ' . $this->f($assetTotal));
        $this->line('  Liabilities    : ' . $this->f($liabTotal));
        $this->line('  Equity (+ NI)  : ' . $this->f($equityTotal));
        $this->line('  Liab + Equity  : ' . $this->f($liabTotal + $equityTotal));
        $this->line('  Balanced?      : ' . ($data['is_balanced'] ? '<fg=green>YES ✓</>' : '<fg=red>NO ✗</>'));
    }

    private function renderIncomeStatement(Accounting $acct, string $start, string $end): void
    {
        $data = $acct->getIncomeStatement($start, $end);
        $rows = [];

        $rows[] = ['<fg=yellow>REVENUE</>', '', ''];

        foreach ($data['revenue']['accounts'] as $item) {
            $rows[] = ['  ' . $item['account']->code, '  ' . $item['account']->name, $this->f($item['balance'])];
        }
        $rows[] = ['', '<options=bold>  Total Revenue</>', $this->f($data['revenue']['total'])];
        $rows[] = ['', '', ''];

        $rows[] = ['<fg=yellow>EXPENSES</>', '', ''];

        foreach ($data['expenses']['accounts'] as $item) {
            $rows[] = ['  ' . $item['account']->code, '  ' . $item['account']->name, $this->f($item['balance'])];
        }
        $rows[] = ['', '<options=bold>  Total Expenses</>', $this->f($data['expenses']['total'])];
        $rows[] = ['', '', ''];
        $rows[] = ['', '<options=bold>  NET INCOME</>', '<options=bold>' . $this->f($data['net_income']) . '</>'];

        $this->table(['Code', 'Account', 'Amount (BDT)'], $rows);
        $this->line('  Revenue  : ' . $this->f($data['revenue']['total']));
        $this->line('  Expenses : ' . $this->f($data['expenses']['total']));
        $this->line('  Net Income: ' . $this->f($data['net_income']));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * 3-MONTH HEAD-WISE FORWARD CASH FLOW PROJECTION
     *
     * Baseline: Q1 actuals (3 months seeded above).
     * Growth:   Revenue collections +5% MoM, payroll flat (25 headcount),
     *           marketing budget escalates slightly, planned capex in M4 & M5.
     * ═══════════════════════════════════════════════════════════════════════ */

    private function renderCashFlowProjection(Accounting $acct, Carbon $fromMonth): void
    {
        // Retrieve actual closing bank balance as opening for projection
        $bankAccount = Account::where('code', '1100')->first();
        $openingBank = $bankAccount ? $bankAccount->getCurrentBalance() : 34_361_000.0;

        $m1 = $fromMonth->format('M Y');
        $m2 = $fromMonth->copy()->addMonth()->format('M Y');
        $m3 = $fromMonth->copy()->addMonths(2)->format('M Y');

        /* ── Projection data structure ───────────────────────────────────── */
        $sections = [
            'OPERATING INFLOWS' => [
                'color' => 'green',
                'heads' => [
                    ['Customer Collections – Sales Revenue',    13_283_000,  13_947_150,  14_644_508],
                    ['Customer Collections – Service Revenue',   3_300_000,   3_465_000,   3_638_250],
                    ['Customer Collections – Manufacturing',     2_750_000,   2_887_500,   3_031_875],
                    ['Management Fee Income (Transfer Pricing)', 1_500_000,   1_500_000,   1_500_000],
                    ['Bank Interest Income',                        55_000,      58_000,      61_000],
                ],
            ],
            'OPERATING OUTFLOWS' => [
                'color' => 'red',
                'heads' => [
                    ['Payroll – Basic Salary (25 staff)',       -2_500_000,  -2_500_000,  -2_500_000],
                    ['Payroll – Allowances (HRA+Transport+Med)',  -875_000,    -875_000,    -875_000],
                    ['Payroll – Commission & Bonus',              -250_000,    -260_000,    -275_000],
                    ['Payroll – Employer PF Contribution',        -125_000,    -125_000,    -125_000],
                    ['TDS & PF Remittance to Govt.',              -600_000,    -600_000,    -600_000],
                    ['Rent – HO + 2 Branch Offices',              -300_000,    -300_000,    -300_000],
                    ['Utilities (Elec., Water, Gas)',               -90_000,     -95_000,    -100_000],
                    ['Office Supplies & Admin',                     -50_000,     -52_000,     -55_000],
                    ['Digital Marketing',                          -500_000,    -550_000,    -600_000],
                    ['Events & Promotions',                        -150_000,    -200_000,    -250_000],
                    ['Market Research',                            -100_000,    -100_000,    -150_000],
                    ['Travel & Conveyance',                         -80_000,     -85_000,     -90_000],
                    ['Staff Entertainment & Misc.',                 -120_000,    -120_000,    -120_000],
                    ['VAT Remittance to NBR',                    -1_867_500,  -2_000_000,  -2_100_000],
                    ['Accounts Payable – Inventory Settlement',  -5_000_000,  -5_000_000,  -5_000_000],
                ],
            ],
            'INVESTING ACTIVITIES' => [
                'color' => 'cyan',
                'heads' => [
                    ['Planned Server Upgrade (M4 capex)',        -2_000_000,           0,           0],
                    ['Warehouse Fit-out (M5 capex)',                      0,  -3_500_000,           0],
                    ['Vehicle Replacement – 1 van (M6)',                  0,           0,  -1_800_000],
                ],
            ],
            'FINANCING ACTIVITIES' => [
                'color' => 'yellow',
                'heads' => [
                    ['Loan Principal Repayment (monthly)',          -500_000,    -500_000,    -500_000],
                    ['Loan Interest Payment',                       -150_000,    -150_000,    -150_000],
                    ['New Equipment Finance Draw-down (M5)',                0,   2_000_000,           0],
                ],
            ],
        ];

        /* ── Render ──────────────────────────────────────────────────────── */
        $this->line('');
        $this->line(str_repeat('═', 80));
        $this->line(sprintf('  3-MONTH HEAD-WISE CASH FLOW PROJECTION   (All amounts in BDT)'));
        $this->line(sprintf('  Based on Q1 actuals with +5%% MoM revenue growth and planned capex'));
        $this->line(str_repeat('═', 80));

        $netFlows = [0.0, 0.0, 0.0];
        $tableRows = [];

        foreach ($sections as $sectionTitle => $section) {
            $tableRows[] = ['<fg=' . $section['color'] . '>' . $sectionTitle . '</>', '', '', ''];
            $sectionTotals = [0.0, 0.0, 0.0];

            foreach ($section['heads'] as [$label, $v1, $v2, $v3]) {
                $tableRows[] = [
                    '  ' . mb_strimwidth($label, 0, 44),
                    $this->pf($v1),
                    $this->pf($v2),
                    $this->pf($v3),
                ];
                $sectionTotals[0] += $v1;
                $sectionTotals[1] += $v2;
                $sectionTotals[2] += $v3;
            }

            $tableRows[] = [
                '<options=bold>  Net ' . $sectionTitle . '</>',
                '<options=bold>' . $this->pf($sectionTotals[0]) . '</>',
                '<options=bold>' . $this->pf($sectionTotals[1]) . '</>',
                '<options=bold>' . $this->pf($sectionTotals[2]) . '</>',
            ];
            $tableRows[] = ['', '', '', ''];

            $netFlows[0] += $sectionTotals[0];
            $netFlows[1] += $sectionTotals[1];
            $netFlows[2] += $sectionTotals[2];
        }

        // Net cash flow
        $closing0 = $openingBank + $netFlows[0];
        $closing1 = $closing0 + $netFlows[1];
        $closing2 = $closing1 + $netFlows[2];

        $tableRows[] = [
            '<options=bold,fg=green>NET CASH FLOW (period)</>',
            '<options=bold,fg=green>' . $this->pf($netFlows[0]) . '</>',
            '<options=bold,fg=green>' . $this->pf($netFlows[1]) . '</>',
            '<options=bold,fg=green>' . $this->pf($netFlows[2]) . '</>',
        ];
        $tableRows[] = [
            'Opening Cash & Bank Balance',
            $this->pf($openingBank),
            $this->pf($closing0),
            $this->pf($closing1),
        ];
        $tableRows[] = [
            '<options=bold>PROJECTED CLOSING BALANCE</>',
            '<options=bold>' . $this->pf($closing0) . '</>',
            '<options=bold>' . $this->pf($closing1) . '</>',
            '<options=bold>' . $this->pf($closing2) . '</>',
        ];

        $this->table(
            ['Head / Activity', $m1, $m2, $m3],
            $tableRows,
        );

        $this->line('  PROJECTION ASSUMPTIONS:');
        $this->line('  • Revenue collections grow ~5% MoM based on Q1 sales trajectory.');
        $this->line('  • Payroll is flat (25 headcount; no new hires budgeted this quarter).');
        $this->line('  • Management fee: fixed ৳1.5M/month per SLA with business units.');
        $this->line('  • Inventory AP: ৳5M/month purchase cycle on 30-day credit terms.');
        $this->line('  • Loan: ৳500K principal + ৳150K interest monthly (5yr term, 12% p.a.).');
        $this->line('  • Capex: Server upgrade M4, warehouse fit-out + equipment finance M5, vehicle M6.');
        $this->line('  • Prepaid insurance: ৳100K residual amortises in M4 (last month of 3-month policy).');
        $this->line('  • Depreciation excluded from cash flow (non-cash; shown in income statement).');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * UI HELPERS
     * ═══════════════════════════════════════════════════════════════════════ */

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('<fg=cyan>' . str_repeat('─', 70) . '</>');
        $this->line("<fg=cyan>  {$title}</>");
        $this->line('<fg=cyan>' . str_repeat('─', 70) . '</>');
    }

    private function printBanner(): void
    {
        $this->newLine();
        $this->line('<fg=green>' . str_repeat('═', 65) . '</>');
        $this->line('<fg=green>  Laravel Accounting — Comprehensive Multi-Entity Demo</>');
        $this->line('<fg=green>  Scenarios covered:</>');
        $this->line('<fg=green>    ✓ Depreciation (3 asset classes, straight-line)</>');
        $this->line('<fg=green>    ✓ Payroll (basic + HRA + transport + medical +</>');
        $this->line('<fg=green>              commission + bonus + employer PF + TDS)</>');
        $this->line('<fg=green>    ✓ Transfer pricing / intercompany management fee</>');
        $this->line('<fg=green>    ✓ Prepaid insurance amortisation (3-month policy)</>');
        $this->line('<fg=green>    ✓ Bank reconciliation (charges + interest income)</>');
        $this->line('<fg=green>    ✓ Marketing (digital / events / market research)</>');
        $this->line('<fg=green>    ✓ Miscellaneous expense heads (8 sub-accounts)</>');
        $this->line('<fg=green>    ✓ 3 business units under one holding company</>');
        $this->line('<fg=green>    ✓ Balance sheet verified balanced at period-end</>');
        $this->line('<fg=green>    ✓ 3-month head-wise forward cash flow projection</>');
        $this->line('<fg=green>' . str_repeat('═', 65) . '</>');
        $this->newLine();
    }

    /** Format a balance figure. */
    private function f(float $amount): string
    {
        return number_format($amount, 2);
    }

    /** Format a projection figure (parenthesis for negatives, dash for zero). */
    private function pf(float $amount): string
    {
        if ($amount === 0.0) {
            return '            —';
        }

        if ($amount < 0) {
            return sprintf('(%s)', number_format(abs($amount)));
        }

        return number_format($amount);
    }
}
