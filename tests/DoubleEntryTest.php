<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Tests;

use Centrex\LaravelAccounting\Accounting as AccountingService;
use Centrex\LaravelAccounting\Exceptions\{DebitsAndCreditsDoNotEqual, InvalidJournalEntryValue, InvalidJournalMethod};
use Centrex\LaravelAccounting\Models\JournalTransaction;
use Money\Money;

class DoubleEntryTest extends BaseTest
{
    public function test_making_sure_we_only_send_debit_or_credit_commands(): void
    {
        $this->expectException(InvalidJournalMethod::class);
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'banana', Money::GBP(100));
    }

    public function test_making_sure_double_entry_value_is_not_zero(): void
    {
        $this->expectException(InvalidJournalEntryValue::class);
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(0));
    }

    public function test_making_sure_double_entry_value_is_not_negative(): void
    {
        $this->expectException(InvalidJournalEntryValue::class);
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(0));
    }

    public function test_making_sure_double_entry_credits_and_debits_match(): void
    {
        $this->expectException(DebitsAndCreditsDoNotEqual::class);
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(9901));
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', Money::GBP(9900));
        $transaction_group->commit();
    }

    public function test_making_sure_post_transaction_journal_values_match(): void
    {
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(10000));
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', Money::GBP(10000));
        $transaction_group->commit();
        $this->assertEquals(
            $this->company_cash_journal->currentBalance(),
            $this->company_ar_journal->currentBalance()->multiply((-1)),
        );
    }

    public function test_transaction_groups_match(): void
    {
        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(10000));
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', Money::GBP(10000));
        $transaction_group->addTransaction($this->company_cash_journal, 'debit', Money::GBP(7500));
        $transaction_group->addTransaction($this->company_ar_journal, 'credit', Money::GBP(7500));
        $transaction_group_uuid = $transaction_group->commit();

        $this->assertEquals(JournalTransaction::where('transaction_group', $transaction_group_uuid)->count(), 4);
    }

    public function test_making_sure_post_transaction_ledgers_match()
    {
        $dollar_value = (int) (mt_rand(1000000, 9999999) * 1.987654321);

        $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
        $transaction_group->addTransaction(
            $this->company_cash_journal,
            'debit',
            Money::USD($dollar_value),
        );
        $transaction_group->addTransaction(
            $this->company_income_journal,
            'credit',
            Money::USD($dollar_value),
        );
        $transaction_group->commit();

        $this->assertEquals(
            $this->company_assets_ledger->currentBalance($this->currency),
            Money::USD($dollar_value),
        );
        $this->assertEquals(
            $this->company_income_ledger->currentBalance($this->currency),
            Money::USD($dollar_value),
        );

        $this->assertEquals(
            $this->company_assets_ledger->currentBalance($this->currency),
            $this->company_income_ledger->currentBalance($this->currency),
        );
    }

    public function test_making_sure_post_transaction_ledgers_match_after_complex_activity(): void
    {
        for ($x = 1; $x <= 1000; $x++) {
            $dollar_value_a = (int) (mt_rand(1, 99999999) * 2.25);
            $dollar_value_b = (int) (mt_rand(1, 99999999) * 3.50);

            $transaction_group = AccountingService::newDoubleEntryTransactionGroup();
            $transaction_group->addTransaction(
                $this->company_cash_journal,
                'debit',
                Money::USD($dollar_value_a),
            );
            $transaction_group->addTransaction(
                $this->company_ar_journal,
                'debit',
                Money::USD($dollar_value_b),
            );
            $transaction_group->addTransaction(
                $this->company_income_journal,
                'credit',
                Money::USD($dollar_value_a + $dollar_value_b),
            );
            $transaction_group->commit();
        }

        $this->assertEquals(
            $this->company_assets_ledger->currentBalance($this->currency),
            $this->company_income_ledger->currentBalance($this->currency),
        );
    }
}
