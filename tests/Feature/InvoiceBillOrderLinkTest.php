<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Livewire\{BillDetails, InvoiceDetails};
use Centrex\Accounting\Models\{Bill, Customer, Invoice, Vendor};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Regression coverage for the Invoice/Bill detail pages' link out to the inventory Sale
 * Order / Purchase Order that generated them — mirrors the existing invoice-table/
 * bill-table "order" column's Route::has() guard (see partials/invoice-table/order.blade.php
 * and partials/bill-table/order.blade.php), just surfaced on the detail page header too.
 * laravel-inventory isn't a dependency of this package's isolated test env, so the
 * `inventory.sale-orders.show` / `inventory.purchase-orders.show` routes are faked here to
 * exercise the "link renders" path — without them, Route::has() is false and only the
 * absent-link path is reachable.
 */
class InvoiceBillOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_links_to_its_sale_order_when_linked_and_the_route_exists(): void
    {
        Route::get('/fake-inventory/sale-orders/{recordId}', fn () => 'ok')->name('inventory.sale-orders.show');

        $invoice = Invoice::factory()->create([
            'customer_id'             => Customer::factory()->create()->id,
            'inventory_sale_order_id' => 42,
            'source_reference'        => 'SO-0042',
            'status'                  => 'draft',
        ]);

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->assertSee('SO-0042')
            ->assertSeeHtml(route('inventory.sale-orders.show', ['recordId' => 42]));
    }

    public function test_invoice_has_no_order_link_when_not_linked_to_a_sale_order(): void
    {
        Route::get('/fake-inventory/sale-orders/{recordId}', fn () => 'ok')->name('inventory.sale-orders.show');

        $invoice = Invoice::factory()->create([
            'customer_id'             => Customer::factory()->create()->id,
            'inventory_sale_order_id' => null,
            'status'                  => 'draft',
        ]);

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->assertDontSee('View Sale Order');
    }

    public function test_invoice_order_link_is_absent_when_the_inventory_route_is_not_registered(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_id'             => Customer::factory()->create()->id,
            'inventory_sale_order_id' => 42,
            'source_reference'        => 'SO-0042',
            'status'                  => 'draft',
        ]);

        Livewire::test(InvoiceDetails::class, ['invoice' => $invoice])
            ->assertDontSee('SO-0042');
    }

    public function test_bill_links_to_its_purchase_order_when_linked_and_the_route_exists(): void
    {
        Route::get('/fake-inventory/purchase-orders/{recordId}', fn () => 'ok')->name('inventory.purchase-orders.show');

        $bill = Bill::factory()->create([
            'vendor_id'                   => Vendor::factory()->create()->id,
            'inventory_purchase_order_id' => 7,
            'source_reference'            => 'PO-0007',
            'status'                      => 'draft',
        ]);

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->assertSee('PO-0007')
            ->assertSeeHtml(route('inventory.purchase-orders.show', ['recordId' => 7]));
    }

    public function test_bill_has_no_order_link_when_not_linked_to_a_purchase_order(): void
    {
        Route::get('/fake-inventory/purchase-orders/{recordId}', fn () => 'ok')->name('inventory.purchase-orders.show');

        $bill = Bill::factory()->create([
            'vendor_id'                   => Vendor::factory()->create()->id,
            'inventory_purchase_order_id' => null,
            'status'                      => 'draft',
        ]);

        Livewire::test(BillDetails::class, ['bill' => $bill])
            ->assertDontSee('View Purchase Order');
    }
}
