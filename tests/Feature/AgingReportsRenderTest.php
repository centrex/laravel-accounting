<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\{ApAgingReport, ArAgingReport};
use Centrex\Accounting\Models\{Bill, Customer, Invoice, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class AgingReportsRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Accounting::class)->initializeChartOfAccounts();
    }

    public function test_ar_aging_report_renders_outstanding_invoices_by_bucket(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Corp']);
        Invoice::factory()->create([
            'customer_id'  => $customer->id,
            'invoice_date' => now()->subDays(45),
            'due_date'     => now()->subDays(40),
            'total'        => 500,
            'paid_amount'  => 0,
            'status'       => 'overdue',
        ]);

        Livewire::test(ArAgingReport::class)
            ->assertOk()
            ->assertSee('AR Aging Report')
            ->assertSee('Acme Corp');
    }

    public function test_ap_aging_report_renders_outstanding_bills_by_bucket(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Office Supplies Ltd']);
        Bill::factory()->create([
            'vendor_id'   => $vendor->id,
            'bill_date'   => now()->subDays(20),
            'due_date'    => now()->subDays(10),
            'total'       => 300,
            'paid_amount' => 0,
            'status'      => 'overdue',
        ]);

        Livewire::test(ApAgingReport::class)
            ->assertOk()
            ->assertSee('AP Aging Report')
            ->assertSee('Office Supplies Ltd');
    }

    public function test_ar_aging_report_shows_empty_state_with_no_outstanding_invoices(): void
    {
        Livewire::test(ArAgingReport::class)
            ->assertOk()
            ->assertSee('No outstanding receivables');
    }
}
