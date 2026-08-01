<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Livewire\Expenses;
use Centrex\Accounting\Models\{Account, Expense, ExpenseItem};
use Centrex\Accounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Exercises Expenses::openEdit()/save() directly on a bare instance rather than through
 * Livewire::test(), since Expenses::render() pulls in <livewire:accounting-expense-table />,
 * whose column definitions (ExpenseTable.php) call Column::currency() — a method added to
 * centrex/tallui after the version currently locked in this package's composer.lock, so any
 * full render of the Expenses page fails in this isolated test env regardless of this
 * feature. Calling the component's public methods directly still exercises the exact same
 * logic under test without going through that unrelated, pre-existing rendering path.
 */
class ExpenseEditLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Accounting $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(Accounting::class);
        $this->seedMinimalAccounts();
    }

    public function test_opening_edit_on_a_draft_expense_loads_its_fields_and_items(): void
    {
        $expense = $this->createDraftExpenseWithItem(vendor: 'Old Vendor', description: 'Old Item', unitPrice: 100);

        $component = new Expenses();
        $component->openEdit($expense->id);

        $this->assertEquals($expense->id, $component->expenseId);
        $this->assertEquals('Old Vendor', $component->vendor_name);
        $this->assertEquals('Old Item', $component->items[0]['description']);
        $this->assertEquals(100.0, $component->items[0]['unit_price']);
        $this->assertTrue($component->showModal);
    }

    public function test_editing_a_draft_expense_updates_its_fields_and_recomputes_totals(): void
    {
        $expense = $this->createDraftExpenseWithItem(vendor: 'Old Vendor', description: 'Old Item', unitPrice: 100);

        $component = new Expenses();
        $component->openEdit($expense->id);
        $component->vendor_name = 'New Vendor';
        $component->reference = 'REF-123';
        $component->items[0]['description'] = 'Updated Item';
        $component->items[0]['unit_price'] = 250;
        $component->items[0]['quantity'] = 2;
        $component->save();

        $fresh = $expense->fresh();
        $this->assertEquals('New Vendor', $fresh->vendor_name);
        $this->assertEquals('REF-123', $fresh->reference);
        $this->assertEquals('draft', $fresh->status);
        $this->assertEquals('500.00', $fresh->subtotal);
        $this->assertEquals('500.00', $fresh->total);
        $this->assertCount(1, $fresh->items);
        $this->assertEquals('Updated Item', $fresh->items->first()->description);
    }

    public function test_editing_can_add_and_remove_line_items(): void
    {
        $expense = $this->createDraftExpenseWithItem(description: 'Item A', unitPrice: 50);

        $component = new Expenses();
        $component->openEdit($expense->id);
        $component->addItem();
        $component->items[1]['description'] = 'Item B';
        $component->items[1]['quantity'] = 1;
        $component->items[1]['unit_price'] = 75;
        $component->save();

        $fresh = $expense->fresh();
        $this->assertCount(2, $fresh->items);
        $this->assertEquals('125.00', $fresh->subtotal);
    }

    public function test_editing_a_posted_expense_is_rejected_and_leaves_it_untouched(): void
    {
        $expense = $this->createDraftExpenseWithItem(vendor: 'Original Vendor', unitPrice: 100, paymentMethod: 'credit');
        $this->accounting->postExpense($expense);

        $component = new Expenses();
        $component->openEdit($expense->id);

        $this->assertNull($component->expenseId);
        $this->assertEquals('Original Vendor', $expense->fresh()->vendor_name);
    }

    public function test_saving_an_expense_that_was_posted_after_the_edit_modal_was_opened_is_rejected(): void
    {
        $expense = $this->createDraftExpenseWithItem(vendor: 'Original Vendor', unitPrice: 100, paymentMethod: 'credit');

        $component = new Expenses();
        $component->openEdit($expense->id);
        $component->vendor_name = 'Tampered Vendor';

        $this->accounting->postExpense($expense->fresh());

        $component->save();

        $this->assertEquals('Original Vendor', $expense->fresh()->vendor_name);
    }

    private function seedMinimalAccounts(): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash',             'type' => 'asset',     'subtype' => 'current_asset'],
            ['code' => '2000', 'name' => 'Accounts Payable',  'type' => 'liability', 'subtype' => 'current_liability'],
            ['code' => '5000', 'name' => 'Office Expense',    'type' => 'expense',   'subtype' => 'operating_expense'],
        ];

        foreach ($accounts as $data) {
            Account::create($data);
        }
    }

    private function createDraftExpenseWithItem(
        string $vendor = 'Test Vendor',
        string $description = 'Test Item',
        float $unitPrice = 100,
        string $paymentMethod = 'cash',
    ): Expense {
        $expense = Expense::create([
            'expense_date'         => now()->toDateString(),
            'subtotal'             => $unitPrice,
            'tax_amount'           => 0,
            'total'                => $unitPrice,
            'currency'             => 'BDT',
            'status'               => 'draft',
            'payment_method'       => $paymentMethod,
            'payment_account_code' => $paymentMethod !== 'credit' ? '1000' : null,
            'vendor_name'          => $vendor,
        ]);

        ExpenseItem::create([
            'expense_id'  => $expense->id,
            'description' => $description,
            'quantity'    => 1,
            'unit_price'  => $unitPrice,
            'amount'      => $unitPrice,
            'tax_rate'    => 0,
            'tax_amount'  => 0,
        ]);

        return $expense;
    }
}
