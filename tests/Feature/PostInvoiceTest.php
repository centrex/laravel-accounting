<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\LaravelAccounting\Accounting;
use Centrex\LaravelAccounting\Models\{Account, Customer, Invoice};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_invoice_creates_journal_entry_and_sets_sent_status(): void
    {
        // seed minimal accounts required
        Account::factory()->create(['code' => '1200', 'name' => 'AR', 'type' => 'asset']);
        Account::factory()->create(['code' => '4000', 'name' => 'Sales', 'type' => 'revenue']);
        Account::factory()->create(['code' => '2300', 'name' => 'Tax', 'type' => 'liability']);

        $customer = Customer::factory()->create();

        $invoice = Invoice::factory()->create([
            'customer_id'    => $customer->id,
            'invoice_date'   => now()->toDateString(),
            'invoice_number' => 'INV-100',
            'subtotal'       => 100,
            'tax_amount'     => 10,
            'total'          => 110,
            'currency'       => 'BDT',
        ]);

        $service = app(Accounting::class);
        $entry = $service->postInvoice($invoice);

        $this->assertNotNull($entry);
        $this->assertEquals('sent', $invoice->fresh()->status);
        $this->assertDatabaseHas('acct_journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    }
}
