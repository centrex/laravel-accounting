<?php

declare(strict_types = 1);

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Account, Customer, Expense, Invoice};

it('nets AR-reducing discounts out of the dashboard outstanding_ar figure', function (): void {
    app(Accounting::class)->initializeChartOfAccounts();

    $customer = Customer::create(['code' => 'AC001', 'name' => 'Acme', 'currency' => 'BDT']);

    $invoice = Invoice::create([
        'customer_id'    => $customer->id,
        'invoice_number' => 'INV-DASH-0001',
        'invoice_date'   => today(),
        'due_date'       => today()->addDays(30),
        'currency'       => 'BDT',
        'exchange_rate'  => 1,
        'subtotal'       => 1000,
        'tax_amount'     => 0,
        'total'          => 1000,
        'status'         => 'sent',
    ]);

    $discountAccount = Account::where('code', '6130')->first();

    Expense::create([
        'chargeable_type' => Invoice::class,
        'chargeable_id'   => $invoice->id,
        'account_id'      => $discountAccount->id,
        'expense_date'    => today(),
        'subtotal'        => 200,
        'tax_amount'      => 0,
        'total'           => 200,
        'paid_amount'     => 200,
        'currency'        => 'BDT',
        'status'          => 'paid',
        'payment_method'  => 'cash',
        'reference'       => $invoice->invoice_number,
    ]);

    // Same query AccountingDashboard::render() now uses for invoiceStats.outstanding_ar
    // (not calling render() itself — it also runs monthlyRevenueExpenses(), which relies on
    // MySQL's MONTH() function and isn't runnable against this suite's SQLite test database).
    $outstandingAr = Invoice::whereIn('status', ['sent', 'issued', 'partially_settled', 'overdue'])
        ->get()->sum('base_balance');

    // Real AR after a $200 discount on a $1000 invoice should be $800, not $1000 — the old
    // raw SUM(total - paid_amount) query ignored the discount entirely.
    expect($outstandingAr)->toBe(800.0);
});
