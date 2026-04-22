<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\Account;
use Centrex\Accounting\Models\Bill;
use Centrex\Accounting\Models\Customer;
use Centrex\Accounting\Models\Invoice;
use Centrex\Accounting\Models\Vendor;
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SbuReportingTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_trial_balance_can_be_filtered_by_sbu_code(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $sales = Account::query()->where('code', '4000')->firstOrFail();
        $expense = Account::query()->where('code', '5000')->firstOrFail();
        $payable = Account::query()->where('code', '2000')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $sales->id, 100, 'OCT');
        $this->postEntry('2025-01-01', $expense->id, $payable->id, 50, 'LUSH');

        $oct = $this->accounting->getTrialBalance('2025-01-01', '2025-01-31', 'oct');
        $this->assertSame('OCT', $oct['sbu_code']);
        $this->assertCount(2, $oct['accounts']);
        $this->assertSame(['1000', '4000'], array_map(
            fn (array $row): string => $row['account']->code,
            $oct['accounts'],
        ));

        $lush = $this->accounting->getTrialBalance('2025-01-01', '2025-01-31', 'LUSH');
        $this->assertCount(2, $lush['accounts']);
        $this->assertSame(['2000', '5000'], array_map(
            fn (array $row): string => $row['account']->code,
            $lush['accounts'],
        ));
    }

    public function test_general_ledger_can_be_filtered_by_sbu_code(): void
    {
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $sales = Account::query()->where('code', '4000')->firstOrFail();

        $this->postEntry('2025-01-01', $cash->id, $sales->id, 100, 'OCT');
        $this->postEntry('2025-01-02', $cash->id, $sales->id, 200, 'LUSH');

        $ledger = $this->accounting->getGeneralLedger($cash->id, '2025-01-01', '2025-01-31', 'LUSH');

        $this->assertSame('LUSH', $ledger['sbu_code']);
        $this->assertCount(1, $ledger['accounts']);
        $this->assertSame(200.0, $ledger['accounts'][0]['period_debits']);
        $this->assertSame('LUSH', $ledger['accounts'][0]['entries'][0]['sbu_code']);
    }

    public function test_post_invoice_infers_sbu_from_linked_inventory_customer(): void
    {
        if (! class_exists('Centrex\\Inventory\\Models\\Customer')) {
            $this->markTestSkipped('Inventory package is not available.');
        }

        $inventoryCustomerClass = 'Centrex\\Inventory\\Models\\Customer';
        $inventoryCustomer = $inventoryCustomerClass::query()->create([
            'code' => 'INV-CUST-001',
            'name' => 'Inventory Customer',
            'meta' => ['default_sbu' => 'oct'],
        ]);

        $customer = Customer::query()->create([
            'code' => 'CUST-001',
            'name' => 'Accounting Customer',
            'currency' => 'BDT',
            'modelable_type' => $inventoryCustomer::class,
            'modelable_id' => $inventoryCustomer->getKey(),
        ]);

        $invoice = Invoice::query()->create([
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 100,
            'tax_amount' => 10,
            'discount_amount' => 0,
            'total' => 110,
            'currency' => 'BDT',
            'status' => 'draft',
        ]);

        $entry = $this->accounting->postInvoice($invoice);
        $payment = $this->accounting->recordInvoicePayment($invoice->fresh(), [
            'amount' => 110,
            'date' => now()->toDateString(),
            'method' => 'cash',
        ]);

        $this->assertSame('OCT', $entry->fresh()->sbu_code);
        $this->assertSame('OCT', $payment->journalEntry->fresh()->sbu_code);
    }

    public function test_post_bill_infers_sbu_from_linked_inventory_supplier(): void
    {
        if (! class_exists('Centrex\\Inventory\\Models\\Supplier')) {
            $this->markTestSkipped('Inventory package is not available.');
        }

        $inventorySupplierClass = 'Centrex\\Inventory\\Models\\Supplier';
        $inventorySupplier = $inventorySupplierClass::query()->create([
            'code' => 'INV-SUP-001',
            'name' => 'Inventory Supplier',
            'meta' => ['default_sbu' => 'it'],
        ]);

        $vendor = Vendor::query()->create([
            'code' => 'VEND-001',
            'name' => 'Accounting Vendor',
            'currency' => 'BDT',
            'modelable_type' => $inventorySupplier::class,
            'modelable_id' => $inventorySupplier->getKey(),
        ]);

        $bill = Bill::query()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 100,
            'tax_amount' => 10,
            'total' => 110,
            'currency' => 'BDT',
            'status' => 'draft',
        ]);

        $entry = $this->accounting->postBill($bill);
        $payment = $this->accounting->recordBillPayment($bill->fresh(), [
            'amount' => 110,
            'date' => now()->toDateString(),
            'method' => 'cash',
        ]);

        $this->assertSame('IT', $entry->fresh()->sbu_code);
        $this->assertSame('IT', $payment->journalEntry->fresh()->sbu_code);
    }

    private function seedMinimalAccounts(): void
    {
        foreach ([
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'operating_revenue'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cost_of_goods_sold'],
        ] as $data) {
            Account::query()->create($data);
        }
    }

    private function postEntry(string $date, int $debitAccountId, int $creditAccountId, float $amount, string $sbuCode): void
    {
        $entry = $this->accounting->createJournalEntry([
            'date' => $date,
            'reference' => 'SBU-' . $sbuCode . '-' . $date,
            'description' => 'SBU reporting test entry',
            'sbu_code' => $sbuCode,
            'lines' => [
                ['account_id' => $debitAccountId, 'type' => 'debit', 'amount' => $amount, 'description' => 'Debit line'],
                ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => 'Credit line'],
            ],
        ]);

        $entry->post();
    }
}
