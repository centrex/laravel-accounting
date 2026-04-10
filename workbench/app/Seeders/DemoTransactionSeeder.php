<?php

declare(strict_types = 1);

namespace Workbench\App\Seeders;

use Carbon\Carbon;
use Centrex\Accounting\Database\Seeders\AccountingSeeder;
use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{
    Account,
    Bill,
    Customer,
    Invoice,
    Vendor
};
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic month of accounting transactions for demo/development use.
 *
 * Transactions seeded:
 *  - 3 customers, 3 vendors
 *  - 3 invoices (paid, partial, draft)
 *  - 2 bills (paid, unpaid)
 *  - 2 manual journal entries (rent + depreciation)
 *
 * Usage:
 *   php artisan db:seed --class=Workbench\\App\\Seeders\\DemoTransactionSeeder
 */
class DemoTransactionSeeder extends Seeder
{
    private Carbon $periodStart;

    private Carbon $periodEnd;

    public function __construct()
    {
        $this->periodStart = now()->startOfMonth();
        $this->periodEnd = now()->endOfMonth();
    }

    public function run(): void
    {
        // Ensure system accounts, fiscal years, customers, vendors and tax rates exist
        $this->call(AccountingSeeder::class);

        // Ensure accounts are present
        Accounting::initializeChartOfAccounts();

        $this->seedInvoices();
        $this->seedBills();
        $this->seedManualJournalEntries();
    }

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------
    private function seedInvoices(): void
    {
        /** @var Customer $cust1 */
        $cust1 = Customer::firstOrCreate(
            ['code' => 'DEMO-CUST-01'],
            [
                'name'          => 'Acme Corporation',
                'email'         => 'billing@acme.example.com',
                'phone'         => '+880-11-0000001',
                'city'          => 'Dhaka',
                'country'       => 'BD',
                'credit_limit'  => 500_000,
                'payment_terms' => 30,
            ],
        );

        /** @var Customer $cust2 */
        $cust2 = Customer::firstOrCreate(
            ['code' => 'DEMO-CUST-02'],
            [
                'name'          => 'Global Industries Ltd',
                'email'         => 'ar@globalindustries.example.com',
                'phone'         => '+880-11-0000002',
                'city'          => 'Chittagong',
                'country'       => 'BD',
                'credit_limit'  => 200_000,
                'payment_terms' => 60,
            ],
        );

        /** @var Customer $cust3 */
        $cust3 = Customer::firstOrCreate(
            ['code' => 'DEMO-CUST-03'],
            [
                'name'          => 'Tech Solutions Inc',
                'email'         => 'finance@techsolutions.example.com',
                'city'          => 'Sylhet',
                'country'       => 'BD',
                'credit_limit'  => 100_000,
                'payment_terms' => 45,
            ],
        );

        // ── Invoice 1: fully paid (BDT, base currency) ──────────────────────
        $inv1 = Invoice::create([
            'customer_id'   => $cust1->id,
            'invoice_date'  => $this->periodStart->copy()->addDays(2),
            'due_date'      => $this->periodStart->copy()->addDays(32),
            'currency'      => 'BDT',
            'exchange_rate' => 1.000000,
            'subtotal'      => 50_000.00,
            'tax_amount'    => 7_500.00,
            'total'         => 57_500.00,
        ]);
        $inv1->items()->createMany([
            ['description' => 'ERP Implementation Phase 1', 'quantity' => 1, 'unit_price' => 35_000.00, 'total' => 35_000.00],
            ['description' => 'Training Sessions (5 days)',  'quantity' => 5, 'unit_price' => 3_000.00, 'total' => 15_000.00],
        ]);
        Accounting::postInvoice($inv1);
        Accounting::recordInvoicePayment($inv1, [
            'date'   => $this->periodStart->copy()->addDays(5),
            'amount' => 57_500.00,
            'method' => 'bank_transfer',
        ]);

        // ── Invoice 2: partially paid (USD foreign currency) ────────────────
        $inv2 = Invoice::create([
            'customer_id'   => $cust2->id,
            'invoice_date'  => $this->periodStart->copy()->addDays(7),
            'due_date'      => $this->periodStart->copy()->addDays(37),
            'currency'      => 'USD',
            'exchange_rate' => 110.000000,  // 1 USD = 110 BDT
            'subtotal'      => 80_000.00,
            'tax_amount'    => 12_000.00,
            'total'         => 92_000.00,
        ]);
        $inv2->items()->create([
            'description' => 'Annual Software License',
            'quantity'    => 1,
            'unit_price'  => 80_000.00,
            'total'       => 80_000.00,
        ]);
        Accounting::postInvoice($inv2);
        Accounting::recordInvoicePayment($inv2, [
            'date'   => $this->periodStart->copy()->addDays(10),
            'amount' => 46_000.00,   // 50 % deposit
            'method' => 'cheque',
        ]);

        // ── Invoice 3: draft (not yet sent, BDT) ────────────────────────────
        $inv3 = Invoice::create([
            'customer_id'   => $cust3->id,
            'invoice_date'  => now(),
            'due_date'      => now()->addDays(30),
            'currency'      => 'BDT',
            'exchange_rate' => 1.000000,
            'subtotal'      => 15_000.00,
            'tax_amount'    => 2_250.00,
            'total'         => 17_250.00,
        ]);
        $inv3->items()->create([
            'description' => 'Consulting Services',
            'quantity'    => 3,
            'unit_price'  => 5_000.00,
            'total'       => 15_000.00,
        ]);
        // left as draft — demonstrating unpublished invoices
    }

    // -------------------------------------------------------------------------
    // Bills
    // -------------------------------------------------------------------------
    private function seedBills(): void
    {
        /** @var Vendor $vend1 */
        $vend1 = Vendor::firstOrCreate(
            ['code' => 'DEMO-VEND-01'],
            [
                'name'          => 'Office Supplies Co',
                'email'         => 'sales@officesupplies.example.com',
                'city'          => 'Dhaka',
                'country'       => 'BD',
                'payment_terms' => 30,
            ],
        );

        /** @var Vendor $vend2 */
        $vend2 = Vendor::firstOrCreate(
            ['code' => 'DEMO-VEND-02'],
            [
                'name'          => 'Cloud Hosting Ltd',
                'email'         => 'billing@cloudhosting.example.com',
                'city'          => 'Dhaka',
                'country'       => 'BD',
                'payment_terms' => 15,
            ],
        );

        // ── Bill 1: paid (BDT, base currency) ───────────────────────────────
        $bill1 = Bill::create([
            'vendor_id'     => $vend1->id,
            'bill_date'     => $this->periodStart->copy()->addDays(1),
            'due_date'      => $this->periodStart->copy()->addDays(31),
            'currency'      => 'BDT',
            'exchange_rate' => 1.000000,
            'subtotal'      => 12_000.00,
            'tax_amount'    => 1_800.00,
            'total'         => 13_800.00,
        ]);
        $bill1->items()->createMany([
            ['description' => 'A4 Paper (20 reams)',  'quantity' => 20, 'unit_price' => 400.00, 'total' => 8_000.00],
            ['description' => 'Printer Ink (6-pack)', 'quantity' => 4,  'unit_price' => 1_000.00, 'total' => 4_000.00],
        ]);
        Accounting::postBill($bill1);
        Accounting::recordBillPayment($bill1, [
            'date'   => $this->periodStart->copy()->addDays(3),
            'amount' => 13_800.00,
            'method' => 'bank_transfer',
        ]);

        // ── Bill 2: unpaid/pending (USD foreign currency) ───────────────────
        $bill2 = Bill::create([
            'vendor_id'     => $vend2->id,
            'bill_date'     => now()->subDays(5),
            'due_date'      => now()->addDays(10),
            'currency'      => 'USD',
            'exchange_rate' => 110.000000,  // 1 USD = 110 BDT
            'subtotal'      => 6_000.00,
            'tax_amount'    => 900.00,
            'total'         => 6_900.00,
        ]);
        $bill2->items()->create([
            'description' => 'Monthly VPS Hosting',
            'quantity'    => 1,
            'unit_price'  => 6_000.00,
            'total'       => 6_000.00,
        ]);
        Accounting::postBill($bill2);
        // intentionally not paid — shows AP outstanding
    }

    // -------------------------------------------------------------------------
    // Manual Journal Entries
    // -------------------------------------------------------------------------
    private function seedManualJournalEntries(): void
    {
        $cash = Account::where('code', '1000')->firstOrFail();
        $rent = Account::where('code', '6100')->firstOrFail();
        $depreciation = Account::where('code', '6600')->firstOrFail();
        $accDepr = Account::where('code', '1800')->firstOrFail();

        // Rent expense for the month
        $rentEntry = Accounting::createJournalEntry([
            'date'        => $this->periodStart,
            'reference'   => 'RENT-' . $this->periodStart->format('Ym'),
            'type'        => 'general',
            'description' => 'Office rent — ' . $this->periodStart->format('F Y'),
            'currency'    => 'BDT',
            'lines'       => [
                ['account_id' => $rent->id, 'type' => 'debit',  'amount' => 25_000.00, 'description' => 'Rent expense'],
                ['account_id' => $cash->id, 'type' => 'credit', 'amount' => 25_000.00, 'description' => 'Cash paid'],
            ],
        ]);
        $rentEntry->post();

        // Monthly depreciation on fixed assets
        $deprEntry = Accounting::createJournalEntry([
            'date'        => $this->periodEnd,
            'reference'   => 'DEPR-' . $this->periodEnd->format('Ym'),
            'type'        => 'adjustment',
            'description' => 'Monthly depreciation — ' . $this->periodStart->format('F Y'),
            'currency'    => 'BDT',
            'lines'       => [
                ['account_id' => $depreciation->id, 'type' => 'debit',  'amount' => 5_000.00, 'description' => 'Depreciation expense'],
                ['account_id' => $accDepr->id,       'type' => 'credit', 'amount' => 5_000.00, 'description' => 'Accumulated depreciation'],
            ],
        ]);
        $deprEntry->post();
    }
}
