<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Tests;

use Centrex\LaravelAccounting\Models\Journal;
use Models\{Account, Product, User};
use Money\Money;

/**
 * Class JournalTest
 */
class JournalTest extends BaseTest
{
    public function test_journals()
    {
        // create some sample model types that will have journals
        $user = $this->createFakeUser();

        // initialize journals for these models
        $user->initJournal('USD');

        // we have created journals
        $this->assertInstanceOf(Journal::class, User::find($user->id)->journal);

        // we get money balances for our journals
        $this->assertInstanceOf(Money::class, User::find($user->id)->journal->balance);

        // our journals have a zero balance
        $this->assertEquals(0, User::find($user->id)->journal->balance->getAmount());

        $user_journal = User::find($user->id)->journal;

        // we can credit a journal and get back dollar balances and standard Money balances
        $user_journal->credit(Money::USD(10000));
        // $user_journal->refresh();
        // dd($user_journal->transactions);

        $this->assertEquals(Money::USD(10000), $user_journal->currentBalance());
        // $this->assertEquals(100, $user_journal->getDollarsCreditedToday());
        // $this->assertEquals(0, $user_journal->getDollarsDebitedToday());
        $this->assertEquals(10000, $user_journal->currentBalance()->getAmount());

        // we can debit a journal
        $user_journal = User::find($user->id)->journal;
        $user_journal->debit(Money::USD(10099));

        // $this->assertEquals(100.99, $user_journal->getDollarsDebitedToday());
        $this->assertEquals(Money::USD(-99), $user_journal->currentBalance());
        $this->assertEquals(-99, $user_journal->currentBalance()->getAmount());
    }

    public function test_journal_object_references()
    {
        /*
        |--------------------------------------------------------------------------
        | setup
        |--------------------------------------------------------------------------
        */

        $user = $this->createFakeUser();
        $user->initJournal('USD');
        $user_journal = $user->fresh()->journal;

        $account = $this->createFakeAccount();
        $account->initJournal('USD');
        $account_journal = Account::find(1)->journal;

        $product      = Product::create(['name' => 'Product 1', 'price' => mt_rand(1, 99999)]);
        $qty_products = mt_rand(25000, 100000);

        // credit the account journal for some products that have been purchased.
        $a_transaction = $account_journal->credit(Money::USD((int) $product->price * $qty_products * 100));

        // reference the product inside this transaction
        // $a_transaction->referencesObject($product);
        $a_transaction->reference()->associate($product);
        // $a_transaction->save();
        // dd($a_transaction);

        // debit the user journal for some products that have been purchased.
        $u_transaction = $user_journal->debit(Money::USD((int) $product->price * $qty_products * 100));

        // reference the product inside this transaction
        // $u_transaction->referencesObject($product);
        $u_transaction->reference()->associate($product);

        /*
        |--------------------------------------------------------------------------
        | assertions
        |--------------------------------------------------------------------------
        */

        // make sure that the amount credited is correct...
        $this->assertEquals(
            Money::USD((int) $product->price * $qty_products * 100),
            $account_journal->currentBalance(),
            'Product Purchase Income',
        );

        // and also that the referenced product can be retrieved from the transaction
        $this->assertInstanceOf(
            $a_transaction->reference_type,
            $a_transaction->reference,
        );
        $this->assertEquals(
            $a_transaction->reference->fresh(),
            $product->fresh(),
        );

        // make sure that the amount debited is correct...
        $this->assertEquals(
            Money::USD((int) -1 * $product->price * $qty_products * 100),
            $user_journal->currentBalance(),
            'Products Purchased',
        );

        // and also that the referenced product can be retrieved from the transaction
        $this->assertInstanceOf(
            $u_transaction->reference_type,
            $u_transaction->reference,
        );

        $this->assertEquals(
            $u_transaction->reference->fresh(),
            $product->fresh(),
        );
    }
}
