<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Tests;

use Centrex\LaravelAccounting\Accounting as AccountingService;
use Money\Money;

/**
 * Class LedgerTest
 */
class LedgerTest extends BaseTest
{
    public function test_ledgers()
    {
        $this->markTestSkipped('must be revisited.');

        // create some user and sell them some stuff on credit
        $number_of_users = mt_rand(5, 10);
        $users           = $this->createFakeUsers($number_of_users);

        foreach ($users as $user) {
            $user_journal = $user->initJournal('USD');
            $user_journal->assignToLedger($this->company_income_ledger);
            $user_journal->credit(Money::USD(100 * 100));
            $this->company_ar_journal->debit(Money::USD(100 * 100));
        }

        // Test if our AR Balance is correct
        $this->assertEquals(
            $number_of_users * -100,
            $this->company_ar_journal->currentBalance()->getAmount(),
        );

        // This is testing that the value on the LEFT side of the books (ASSETS) is the same as the RIGHT side (L + OE + nominals)
        $this->assertEquals($number_of_users * 100, $this->company_assets_ledger->getCurrentBalanceInDollars($this->currency));
        $this->assertEquals($number_of_users * 100, $this->company_income_ledger->getCurrentBalanceInDollars($this->currency));
        $this->assertEquals(
            $this->company_assets_ledger->getCurrentBalance($this->currency),
            $this->company_income_ledger->getCurrentBalance($this->currency),
        );

        // At this point we have no cash on hand
        $this->assertTrue($this->company_cash_journal->currentBalance()->isZero());

        // customer makes a payment (use double entry service)
        $user_making_payment = $users[0];
        $payment_1           = mt_rand(3, 30) * 1.0129; // convert us using Faker dollar amounts

        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', $payment_1, 'Payment from User ' . $user_making_payment->id, $user_making_payment);
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', $payment_1, 'Payment from User ' . $user_making_payment->id, $user_making_payment);
        $transaction_group->commit();

        // customer makes a payment (use double entry service)
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $payment_2         = mt_rand(3, 30) * 1.075;
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', $payment_2, 'Payment from User ' . $user_making_payment->id, $user_making_payment);
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', $payment_2, 'Payment from User ' . $user_making_payment->id, $user_making_payment);
        $transaction_group->commit();

        // these are asset accounts, so their balances are reversed
        $total_payment_made = (((int) ($payment_1 * 100)) / 100) + (((int) ($payment_2 * 100)) / 100);
        $this->assertEquals(
            $this->company_cash_journal->getCurrentBalanceInDollars(),
            (-1) * $total_payment_made,
            'Company Cash Is Not Correct',
        );

        $this->assertEquals(
            $this->company_ar_journal->getCurrentBalanceInDollars(),
            (-1) * (($number_of_users * 100) - $total_payment_made),
            'AR Does Not Reflects Cash Payments Made');

        // check the value of all the payments made by this user?
        $dollars_paid_by_user = $this->company_cash_journal->transactionsReferencingObjectQuery($user_making_payment)->get()->sum('debit') / 100;
        $this->assertEquals($dollars_paid_by_user, $total_payment_made, 'User payments did not match what was recorded.');

        // check the "balance due" (the amount still owed by this user)
        $this->assertEquals(
            $user_journal->getCurrentBalanceInDollars() - $dollars_paid_by_user,
            100                                         - $total_payment_made,
            'User Current Balance does not reflect their payment amounts',
        );

        // still make sure our ledger balances match
        $this->assertEquals($this->company_assets_ledger->getCurrentBalanceInDollars($this->currency), $this->company_income_ledger->getCurrentBalanceInDollars($this->currency));
    }
}
