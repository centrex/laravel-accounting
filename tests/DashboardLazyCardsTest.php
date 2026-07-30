<?php

declare(strict_types = 1);

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\{AccountingBalanceSnapshotCard, AccountingCurrentAssetsCard, AccountingKpiCard, AccountingPayablesCard, AccountingReceivablesCard};
use Centrex\Accounting\Models\{Account, Bill, Customer, Expense, Invoice, Vendor};
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    app(Accounting::class)->initializeChartOfAccounts();
});

it('AccountingKpiCard reports revenue/expenses for the given date range and caches the result', function (): void {
    $revenue = Account::where('code', '4000')->first();
    $cash = Account::where('code', '1000')->first();

    app(Accounting::class)->createJournalEntry([
        'date'        => today(),
        'reference'   => 'REF-1',
        'type'        => 'general',
        'description' => 'Sale',
        'currency'    => 'BDT',
        'lines'       => [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 500],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 500],
        ],
    ])->post();

    Livewire::test(AccountingKpiCard::class, [
        'startDate' => today()->startOfMonth(),
        'endDate'   => today()->endOfMonth(),
    ])->assertViewHas('metrics', fn (array $metrics) => (float) $metrics['revenue'] === 500.0);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    // Same date range as above -> should hit the array-store cache, not re-run getIncomeStatement()/getBalanceSheet().
    Livewire::test(AccountingKpiCard::class, [
        'startDate' => today()->startOfMonth(),
        'endDate'   => today()->endOfMonth(),
    ]);

    expect($queryCount)->toBe(0);
});

it('AccountingKpiCard placeholder renders a skeleton', function (): void {
    $component = new AccountingKpiCard();

    expect($component->placeholder())->toBeString()->toContain('role="status"');
});

it('AccountingReceivablesCard nets AR-reducing discounts', function (): void {
    $customer = Customer::create(['code' => 'AC001', 'name' => 'Acme', 'currency' => 'BDT']);

    $invoice = Invoice::create([
        'customer_id'    => $customer->id,
        'invoice_number' => 'INV-0001',
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
        'subtotal'        => 100,
        'tax_amount'      => 0,
        'total'           => 100,
        'paid_amount'     => 100,
        'currency'        => 'BDT',
        'status'          => 'paid',
        'payment_method'  => 'cash',
        'reference'       => $invoice->invoice_number,
    ]);

    $component = new AccountingReceivablesCard();
    $component->mount();

    expect($component->invoiceStats()['outstanding_ar'])->toBe(900.0);
});

it('AccountingPayablesCard sums outstanding bill balances', function (): void {
    $vendor = Vendor::create(['code' => 'V001', 'name' => 'Supplier', 'currency' => 'BDT']);

    Bill::create([
        'vendor_id'     => $vendor->id,
        'bill_number'   => 'BILL-0001',
        'bill_date'     => today(),
        'due_date'      => today()->addDays(30),
        'currency'      => 'BDT',
        'exchange_rate' => 1,
        'subtotal'      => 400,
        'tax_amount'    => 0,
        'total'         => 400,
        'status'        => 'sent',
    ]);

    $component = new AccountingPayablesCard();
    $component->mount();

    expect($component->billStats()['outstanding_ap'])->toBe(400.0);
});

it('AccountingBalanceSnapshotCard and AccountingCurrentAssetsCard share one cached getBalanceSheet() call', function (): void {
    $cash = Account::where('code', '1000')->first();
    $revenue = Account::where('code', '4000')->first();

    app(Accounting::class)->createJournalEntry([
        'date'        => today(),
        'reference'   => 'REF-2',
        'type'        => 'general',
        'description' => 'Sale',
        'currency'    => 'BDT',
        'lines'       => [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 300],
            ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 300],
        ],
    ])->post();

    $snapshot = new AccountingBalanceSnapshotCard();
    $snapshot->endDate = today()->endOfMonth();
    $snapshot->mount();

    expect($snapshot->balances()['total_assets'])->toBeGreaterThanOrEqual(300.0);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    // Same cache key (same endDate) as the snapshot card above -> getBalanceSheet() must not re-run.
    $currentAssets = new AccountingCurrentAssetsCard();
    $currentAssets->endDate = today()->endOfMonth();
    $currentAssets->mount();
    $currentAssets->balanceSheet();

    expect($queryCount)->toBe(0);
});
