<?php

declare(strict_types = 1);

namespace Workbench\App\Commands;

use Centrex\LaravelAccounting\Facades\Accounting;
use Centrex\LaravelAccounting\Models\{
    Account,
    Bill,
    Customer,
    FiscalYear,
    Invoice,
    Vendor
};
use Illuminate\Console\Command;

/**
 * Demonstrates the complete laravel-accounting workflow:
 *
 *  1. Initialize chart of accounts
 *  2. Customer invoice → post → full payment
 *  3. Customer invoice → post → partial payment
 *  4. Vendor bill     → post → payment
 *  5. Manual journal entry (rent)
 *  6. Financial reports (trial balance, P&L, balance sheet, cash flow)
 *  7. Fiscal year closing
 *
 * Usage:
 *   php artisan accounting:demo
 *   php artisan accounting:demo --skip-close   # skip fiscal year closing
 */
class DemoWorkflowCommand extends Command
{
    protected $signature = 'accounting:demo {--skip-close : Skip fiscal-year closing step}';

    protected $description = 'Run a complete laravel-accounting demo workflow';

    public function handle(): int
    {
        $this->newLine();
        $this->info('=============================================================');
        $this->info('  laravel-accounting — Demo Workflow');
        $this->info('=============================================================');

        $this->step1InitChartOfAccounts();
        $this->step2FullyPaidInvoice();
        $this->step3PartiallyPaidInvoice();
        $this->step4VendorBill();
        $this->step5ManualJournalEntry();
        $this->step6FinancialReports();

        if (! $this->option('skip-close')) {
            $this->step7CloseFiscalYear();
        }

        $this->newLine();
        $this->info('=============================================================');
        $this->info('  Demo complete!');
        $this->info('=============================================================');
        $this->newLine();

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Step 1 – Chart of Accounts
    // -------------------------------------------------------------------------
    private function step1InitChartOfAccounts(): void
    {
        $this->sectionHeader('Step 1 — Initialize Chart of Accounts');

        Accounting::initializeChartOfAccounts();

        $count = Account::count();
        $this->line("  ✓ Chart of accounts seeded ({$count} accounts)");

        $this->table(
            ['Code', 'Name', 'Type', 'Sub-type'],
            Account::orderBy('code')->get()->map(fn ($a) => [
                $a->code,
                $a->name,
                $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
                $a->subtype instanceof \BackedEnum ? $a->subtype->value : $a->subtype,
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Step 2 – Invoice: post → full payment
    // -------------------------------------------------------------------------
    private function step2FullyPaidInvoice(): void
    {
        $this->sectionHeader('Step 2 — Customer Invoice (fully paid)');

        $customer = $this->ensureCustomer('DEMO-CUST-01', 'Acme Corporation', 'billing@acme.example.com');
        $this->line("  Customer : {$customer->name} (#{$customer->id})");

        // Create invoice
        $invoice = Invoice::create([
            'customer_id'  => $customer->id,
            'invoice_date' => today()->subDays(10),
            'due_date'     => today()->addDays(20),
            'currency'     => config('accounting.base_currency', 'BDT'),
            'subtotal'     => 10_000.00,
            'tax_amount'   => 1_500.00,
            'total'        => 11_500.00,
        ]);
        $invoice->items()->createMany([
            ['description' => 'Web Development Services', 'quantity' => 2, 'unit_price' => 4_000.00, 'total' => 8_000.00],
            ['description' => 'Domain Registration',      'quantity' => 1, 'unit_price' => 2_000.00, 'total' => 2_000.00],
        ]);
        $this->line("  Invoice  : {$invoice->invoice_number}  total = {$invoice->total}");

        // Post invoice → DR Accounts Receivable / CR Sales Revenue + Tax
        $je = Accounting::postInvoice($invoice);
        $this->line("  Posted   : journal entry {$je->entry_number}  status = {$je->status->value}");
        $this->showJournalLines($je);

        // Record full payment
        $payment = Accounting::recordInvoicePayment($invoice, [
            'date'   => today(),
            'amount' => 11_500.00,
            'method' => 'bank_transfer',
        ]);
        $invoice->refresh();
        $this->line("  Payment  : {$payment->payment_number}  invoice status = {$invoice->status->value}");
    }

    // -------------------------------------------------------------------------
    // Step 3 – Invoice: post → partial payment
    // -------------------------------------------------------------------------
    private function step3PartiallyPaidInvoice(): void
    {
        $this->sectionHeader('Step 3 — Customer Invoice (partial payment)');

        $customer = $this->ensureCustomer('DEMO-CUST-02', 'Global Industries Ltd', 'ar@globalindustries.example.com');

        $invoice = Invoice::create([
            'customer_id'  => $customer->id,
            'invoice_date' => today()->subDays(5),
            'due_date'     => today()->addDays(25),
            'currency'     => config('accounting.base_currency', 'BDT'),
            'subtotal'     => 20_000.00,
            'tax_amount'   => 3_000.00,
            'total'        => 23_000.00,
        ]);
        $invoice->items()->create([
            'description' => 'Annual Support Contract',
            'quantity'    => 1,
            'unit_price'  => 20_000.00,
            'total'       => 20_000.00,
        ]);
        $this->line("  Invoice  : {$invoice->invoice_number}  total = {$invoice->total}");

        Accounting::postInvoice($invoice);

        // Pay 50 %
        Accounting::recordInvoicePayment($invoice, [
            'date'   => today(),
            'amount' => 11_500.00,
            'method' => 'cheque',
        ]);
        $invoice->refresh();
        $this->line("  After partial payment — status = {$invoice->status->value}  balance = {$invoice->balance}");
    }

    // -------------------------------------------------------------------------
    // Step 4 – Vendor bill: post → payment
    // -------------------------------------------------------------------------
    private function step4VendorBill(): void
    {
        $this->sectionHeader('Step 4 — Vendor Bill');

        $vendor = $this->ensureVendor('DEMO-VEND-01', 'Office Supplies Co', 'sales@officesupplies.example.com');
        $this->line("  Vendor   : {$vendor->name} (#{$vendor->id})");

        $bill = Bill::create([
            'vendor_id' => $vendor->id,
            'bill_date' => today()->subDays(3),
            'due_date'  => today()->addDays(27),
            'currency'  => config('accounting.base_currency', 'BDT'),
            'subtotal'  => 8_000.00,
            'tax_amount'=> 1_200.00,
            'total'     => 9_200.00,
        ]);
        $bill->items()->createMany([
            ['description' => 'A4 Paper (10 reams)', 'quantity' => 10, 'unit_price' => 500.00, 'total' => 5_000.00],
            ['description' => 'Ink Cartridges',       'quantity' => 6,  'unit_price' => 500.00, 'total' => 3_000.00],
        ]);
        $this->line("  Bill     : {$bill->bill_number}  total = {$bill->total}");

        $je = Accounting::postBill($bill);
        $this->line("  Posted   : journal entry {$je->entry_number}");
        $this->showJournalLines($je);

        Accounting::recordBillPayment($bill, [
            'date'   => today(),
            'amount' => 9_200.00,
            'method' => 'bank_transfer',
        ]);
        $bill->refresh();
        $this->line("  Bill status after payment = {$bill->status->value}");
    }

    // -------------------------------------------------------------------------
    // Step 5 – Manual Journal Entry (monthly rent)
    // -------------------------------------------------------------------------
    private function step5ManualJournalEntry(): void
    {
        $this->sectionHeader('Step 5 — Manual Journal Entry (rent expense)');

        $rentExpense = Account::where('code', '6100')->firstOrFail();
        $cash        = Account::where('code', '1000')->firstOrFail();

        $entry = Accounting::createJournalEntry([
            'date'        => today(),
            'reference'   => 'RENT-' . now()->format('Ym'),
            'type'        => 'general',
            'description' => 'Monthly office rent — ' . now()->format('F Y'),
            'currency'    => config('accounting.base_currency', 'BDT'),
            'lines'       => [
                ['account_id' => $rentExpense->id, 'type' => 'debit',  'amount' => 30_000.00, 'description' => 'Rent expense'],
                ['account_id' => $cash->id,         'type' => 'credit', 'amount' => 30_000.00, 'description' => 'Cash paid'],
            ],
        ]);
        $entry->post();

        $this->line("  Entry    : {$entry->entry_number}  balanced = " . ($entry->isBalanced() ? 'yes' : 'no'));
        $this->showJournalLines($entry);
    }

    // -------------------------------------------------------------------------
    // Step 6 – Financial Reports
    // -------------------------------------------------------------------------
    private function step6FinancialReports(): void
    {
        $this->sectionHeader('Step 6 — Financial Reports');

        $start = today()->startOfYear()->toDateString();
        $end   = today()->toDateString();

        // Trial Balance
        $tb = Accounting::getTrialBalance($start, $end);
        $this->comment('  ── Trial Balance ─────────────────────────────────────');
        $this->table(
            ['Account', 'Debit', 'Credit'],
            collect($tb['accounts'])->map(fn ($row) => [
                $row['account']->code . ' ' . $row['account']->name,
                number_format((float) $row['debit'],  2),
                number_format((float) $row['credit'], 2),
            ]),
        );
        $this->line(sprintf(
            '  Totals → Debit: %s  Credit: %s  Balanced: %s',
            number_format($tb['total_debits'], 2),
            number_format($tb['total_credits'], 2),
            $tb['is_balanced'] ? 'YES' : 'NO',
        ));

        // Income Statement
        $pl = Accounting::getIncomeStatement($start, $end);
        $this->comment('  ── Income Statement ──────────────────────────────────');
        $this->line(sprintf('  Revenue  : %s', number_format($pl['revenue']['total'] ?? 0, 2)));
        $this->line(sprintf('  Expenses : %s', number_format($pl['expenses']['total'] ?? 0, 2)));
        $this->line(sprintf('  Net Income: %s', number_format($pl['net_income'], 2)));

        // Balance Sheet
        $bs = Accounting::getBalanceSheet(today());
        $this->comment('  ── Balance Sheet ─────────────────────────────────────');
        $this->line(sprintf('  Assets      : %s', number_format($bs['assets']['total'] ?? 0, 2)));
        $this->line(sprintf('  Liabilities : %s', number_format($bs['liabilities']['total'] ?? 0, 2)));
        $this->line(sprintf('  Equity      : %s', number_format($bs['equity']['total'] ?? 0, 2)));
        $this->line(sprintf('  Balanced    : %s', $bs['is_balanced'] ? 'YES' : 'NO'));

        // Cash Flow
        $cf = Accounting::getCashFlowStatement($start, $end);
        $this->comment('  ── Cash Flow Statement ───────────────────────────────');
        $this->line(sprintf('  Operating : %s', number_format($cf['operating_activities'], 2)));
        $this->line(sprintf('  Investing : %s', number_format($cf['investing_activities'], 2)));
        $this->line(sprintf('  Financing : %s', number_format($cf['financing_activities'], 2)));
        $this->line(sprintf('  Net Change: %s', number_format($cf['net_change'], 2)));
    }

    // -------------------------------------------------------------------------
    // Step 7 – Close Fiscal Year
    // -------------------------------------------------------------------------
    private function step7CloseFiscalYear(): void
    {
        $this->sectionHeader('Step 7 — Fiscal Year Closing');

        // Use the current year's fiscal year if it exists; otherwise warn and skip.
        $fy = FiscalYear::where('name', 'FY ' . now()->year)->first()
            ?? FiscalYear::where('is_current', true)->first();

        if (! $fy) {
            $this->warn('  No fiscal year found — run AccountingSeeder first or create one manually.');
            $this->line('  Example: FiscalYear::create([\'name\' => \'FY ' . now()->year . '\', ...])');

            return;
        }

        if ($fy->is_closed) {
            $this->warn("  Fiscal year '{$fy->name}' is already closed — skipping.");

            return;
        }

        Accounting::closeFiscalYear($fy);
        $fy->refresh();
        $this->line("  ✓ Fiscal year '{$fy->name}' closed — is_closed = " . ($fy->is_closed ? 'true' : 'false'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ensureCustomer(string $code, string $name, string $email): Customer
    {
        return Customer::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'email' => $email, 'payment_terms' => 30, 'credit_limit' => 100_000],
        );
    }

    private function ensureVendor(string $code, string $name, string $email): Vendor
    {
        return Vendor::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'email' => $email, 'payment_terms' => 30],
        );
    }

    private function showJournalLines(\Centrex\LaravelAccounting\Models\JournalEntry $entry): void
    {
        $this->table(
            ['Account', 'Type', 'Amount'],
            $entry->lines->map(fn ($l) => [
                $l->account->code . ' ' . $l->account->name,
                strtoupper((string) $l->type),
                number_format((float) $l->amount, 2),
            ]),
        );
    }

    private function sectionHeader(string $title): void
    {
        $this->newLine();
        $this->line('<fg=cyan>-------------------------------------------------------------</>');
        $this->line("<fg=cyan>  {$title}</>");
        $this->line('<fg=cyan>-------------------------------------------------------------</>');
    }
}
