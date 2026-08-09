<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\{CreditMemoDetails, CreditMemos};
use Centrex\Accounting\Models\{Account, Customer, Invoice};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * x-tallui-modal opens/closes exclusively via the 'open-modal'/'close-modal' browser
 * events matched against its id prop (see tallui/docs/modal.md) — it has no wire:model
 * support at all. These components used to set a plain public bool and bind it with
 * wire:model, which silently did nothing client-side (the Alpine 'open' state never
 * moved), so the Refund and New Credit Memo modals never appeared. Assert the fix: the
 * actions dispatch the real events instead.
 */
class CreditMemoModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccounts();
    }

    private function postedInvoice(float $subtotal = 100): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id'     => Customer::factory()->create()->id,
            'invoice_date'    => now()->toDateString(),
            'subtotal'        => $subtotal,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total'           => $subtotal,
            'currency'        => 'BDT',
            'status'          => 'draft',
        ]);
        app(Accounting::class)->postInvoice($invoice);

        return $invoice->fresh();
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',                       'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable',        'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2300', 'name' => 'Sales Tax Payable',          'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '4000', 'name' => 'Sales Revenue',              'type' => 'revenue',   'subtype' => 'operating_revenue'],
            ['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'expense',   'subtype' => 'selling_expense'],
        ];

        foreach ($accounts as $data) {
            Account::factory()->create($data);
        }
    }

    public function test_opening_the_refund_modal_dispatches_the_open_modal_event(): void
    {
        $invoice = $this->postedInvoice(100);
        $memo = app(Accounting::class)->createCreditMemo($invoice, ['subtotal' => 40]);
        app(Accounting::class)->issueCreditMemo($memo);

        Livewire::test(CreditMemoDetails::class, ['creditMemo' => $memo->fresh()])
            ->call('openRefundModal')
            ->assertDispatched('open-modal', 'refund-modal');
    }

    public function test_recording_a_refund_dispatches_the_close_modal_event(): void
    {
        $invoice = $this->postedInvoice(100);
        $memo = app(Accounting::class)->createCreditMemo($invoice, ['subtotal' => 40]);
        app(Accounting::class)->issueCreditMemo($memo);

        Livewire::test(CreditMemoDetails::class, ['creditMemo' => $memo->fresh()])
            ->set('refund_amount', '40')
            ->set('refund_date', now()->format('Y-m-d'))
            ->set('refund_method', 'cash')
            ->set('refund_account_code', '1000')
            ->call('recordRefund')
            ->assertDispatched('close-modal', 'refund-modal');
    }

    public function test_opening_the_create_modal_dispatches_the_open_modal_event(): void
    {
        Livewire::test(CreditMemos::class)
            ->call('openCreate')
            ->assertDispatched('open-modal', 'credit-memo-create-modal');
    }

    public function test_saving_a_new_credit_memo_dispatches_the_close_modal_event(): void
    {
        $invoice = $this->postedInvoice(100);

        Livewire::test(CreditMemos::class)
            ->set('invoice_id', $invoice->id)
            ->set('memo_date', now()->format('Y-m-d'))
            ->set('subtotal', '40')
            ->call('save')
            ->assertDispatched('close-modal', 'credit-memo-create-modal');
    }
}
