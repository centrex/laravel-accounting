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

    /**
     * The bill-table "order" column partial (partials/bill-table/order.blade.php) is exercised
     * directly here rather than through Livewire::test(BillTable::class) — this package's own
     * isolated test env pins a published centrex/tallui release that predates
     * Column::currency() (used elsewhere in BillTable::columns()), so a full component render
     * fails on that unrelated, pre-existing version mismatch. The host app (laravel-erp)
     * path-repos centrex/tallui to the current sibling package, where BillTable renders fine —
     * only this package's standalone test workbench is affected.
     */
    public function test_bill_table_order_column_links_a_freight_bill_to_its_shipment(): void
    {
        Route::get('/fake-inventory/shipments/{recordId}', fn () => 'ok')->name('inventory.shipments.show');
        // Route::has() reads a name→route lookup table that's snapshotted when a route is
        // add()ed to the collection — before ->name() is chained onto it — so a route registered
        // this way needs an explicit refresh before Route::has() (called from inside the blade
        // partial under test) will see it. Livewire::test() triggers this refresh as a side
        // effect of its own dispatch, which is why the other tests in this file don't need it.
        Route::getRoutes()->refreshNameLookups();

        $bill = Bill::factory()->create([
            'vendor_id'        => Vendor::factory()->create()->id,
            'source_type'      => 'Centrex\\Inventory\\Models\\Shipment',
            'source_id'        => 9,
            'source_reference' => 'SHP-0009',
            'status'           => 'draft',
        ]);

        $html = view('accounting::livewire.partials.bill-table.order', ['row' => $bill, 'value' => $bill->source_reference])->render();

        $this->assertStringContainsString(route('inventory.shipments.show', ['recordId' => 9]), $html);
        $this->assertStringContainsString('SHP-0009', $html);
    }

    public function test_bill_table_order_column_links_a_freight_bill_to_its_transfer(): void
    {
        Route::get('/fake-inventory/transfers/{recordId}', fn () => 'ok')->name('inventory.transfers.show');
        Route::getRoutes()->refreshNameLookups();

        $bill = Bill::factory()->create([
            'vendor_id'        => Vendor::factory()->create()->id,
            'source_type'      => 'Centrex\\Inventory\\Models\\Transfer',
            'source_id'        => 4,
            'source_reference' => 'TRF-0004',
            'status'           => 'draft',
        ]);

        $html = view('accounting::livewire.partials.bill-table.order', ['row' => $bill, 'value' => $bill->source_reference])->render();

        $this->assertStringContainsString(route('inventory.transfers.show', ['recordId' => 4]), $html);
        $this->assertStringContainsString('TRF-0004', $html);
    }

    public function test_bill_table_order_column_shows_plain_reference_when_the_inventory_route_is_not_registered(): void
    {
        $bill = Bill::factory()->create([
            'vendor_id'        => Vendor::factory()->create()->id,
            'source_type'      => 'Centrex\\Inventory\\Models\\Shipment',
            'source_id'        => 11,
            'source_reference' => 'SHP-0011',
            'status'           => 'draft',
        ]);

        $html = view('accounting::livewire.partials.bill-table.order', ['row' => $bill, 'value' => $bill->source_reference])->render();

        $this->assertStringContainsString('SHP-0011', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }
}
