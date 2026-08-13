<?php

declare(strict_types = 1);

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\FinancialReports;
use Centrex\Accounting\Models\{Account, Customer, Invoice};
use Livewire\Livewire;

beforeEach(function (): void {
    app(Accounting::class)->initializeChartOfAccounts();
});

it('renders the cash flow statement with breakdown and opening/closing balance', function (): void {
    $cash = Account::where('code', '1000')->first();
    $revenue = Account::where('code', '4000')->first();

    app(Accounting::class)->createJournalEntry([
        'date'  => today(),
        'lines' => [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 500],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 500],
        ],
    ])->post();

    Livewire::test(FinancialReports::class)
        ->set('reportType', 'cash_flow')
        ->call('generateReport')
        ->assertOk()
        ->assertSee('Opening Cash Balance')
        ->assertSee('Closing Cash Balance')
        ->assertSee('Net Change in Cash');
});

it('renders the cash flow forecast with weekly buckets and AR/AP schedules', function (): void {
    $customer = Customer::factory()->create();
    $invoice = Invoice::factory()->create([
        'customer_id'     => $customer->id,
        'invoice_date'    => today(),
        'due_date'        => today()->addWeeks(2),
        'subtotal'        => 500,
        'tax_amount'      => 0,
        'discount_amount' => 0,
        'total'           => 500,
        'currency'        => 'BDT',
        'status'          => 'draft',
    ]);
    app(Accounting::class)->postInvoice($invoice);

    Livewire::test(FinancialReports::class)
        ->set('reportType', 'cash_flow_forecast')
        ->call('generateReport')
        ->assertOk()
        ->assertSee('Cash Flow Forecast')
        ->assertSee('Starting Cash')
        ->assertSee('Outstanding Invoices')
        ->assertSee($invoice->invoice_number);
});

it('renders the cash book grouped by day with daily subtotals', function (): void {
    $cash = Account::where('code', '1000')->first();
    $revenue = Account::where('code', '4000')->first();

    app(Accounting::class)->createJournalEntry([
        'date'  => today(),
        'lines' => [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 250],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 250],
        ],
    ])->post();

    Livewire::test(FinancialReports::class)
        ->set('reportType', 'cash_book')
        ->call('generateReport')
        ->assertOk()
        ->assertSee('Day total');
});
