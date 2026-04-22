<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Models\{Account, Bill, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class BillsLivewirePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalAccounts();
    }

    public function test_bill_payment_from_livewire_creates_payment_journal(): void
    {
        $bill = $this->createIssuedBill(total: 150);

        Livewire::test(\Centrex\Accounting\Livewire\Bills::class)
            ->call('openPayModal', $bill->id)
            ->set('pay_date', '2025-06-01')
            ->set('pay_amount', '150')
            ->set('pay_method', 'cash')
            ->set('pay_reference', 'CHK-123')
            ->set('pay_notes', 'Paid in full.')
            ->call('recordPayment');

        $bill->refresh();
        $payment = $bill->payments()->latest('id')->first();

        $this->assertNotNull($payment);
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertEquals('settled', $bill->status->value);
        $this->assertEquals('150.00', $bill->paid_amount);
        $this->assertDatabaseHas('acct_journal_entries', [
            'id' => $payment->journal_entry_id,
            'status' => 'posted',
            'reference' => $payment->payment_number,
        ]);
    }

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    private function createIssuedBill(float $subtotal = 140, float $tax = 10, float $total = 150): Bill
    {
        $vendor = Vendor::factory()->create();

        return Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'bill_date' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'currency' => 'BDT',
            'status' => 'issued',
        ]);
    }
}
