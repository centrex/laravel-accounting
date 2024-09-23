<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting;

use Carbon\Carbon;
use Centrex\LaravelAccounting\Exceptions\{DebitsAndCreditsDoNotEqual, InvalidJournalEntryValue, InvalidJournalMethod, TransactionCouldNotBeProcessed};
use Centrex\LaravelAccounting\Models\Journal;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Money\Money;

class Accounting
{
    /** @var array */
    protected $transactionsPending = [];

    public static function newDoubleEntryTransactionGroup(): Accounting
    {
        return new self();
    }

    /**
     * @param  string  $method  'credit' or 'debit'
     * @param  Money  $money  The amount of money to credit or debit.
     *
     * @throws InvalidJournalEntryValue
     * @throws InvalidJournalMethod
     *
     * @internal param int $value
     */
    public function addTransaction(
        Journal $journal,
        string $method,
        Money $money,
        ?string $memo = null,
        $referenced_object = null,
        ?Carbon $postdate = null,
    ): void {
        if (!in_array($method, ['credit', 'debit'])) {
            throw new InvalidJournalMethod();
        }

        if ($money->getAmount() <= 0) {
            throw new InvalidJournalEntryValue();
        }

        $this->transactionsPending[] = [
            'journal'           => $journal,
            'method'            => $method,
            'money'             => $money,
            'memo'              => $memo,
            'referenced_object' => $referenced_object,
            'postdate'          => $postdate,
        ];
    }

    public function transactionsPending(): array
    {
        return $this->transactionsPending;
    }

    /** Save a transaction group. */
    public function commit(): string
    {
        $this->assertTransactionCreditsEqualDebits();

        try {
            return DB::transaction(function (): string {
                $transactionGroupUuid = (string) Str::orderedUuid();

                foreach ($this->transactionsPending as $transaction_pending) {
                    $transaction = $transaction_pending['journal']->{$transaction_pending['method']}(
                        $transaction_pending['money'],
                        $transaction_pending['memo'],
                        $transaction_pending['postdate'],
                        $transactionGroupUuid,
                    );

                    if ($object = $transaction_pending['referenced_object']) {
                        $transaction->reference()->associate($object);
                    }
                }

                return $transactionGroupUuid;
            });
        } catch (Exception $e) {
            throw new TransactionCouldNotBeProcessed('Rolling Back Database. Message: ' . $e->getMessage());
        }
    }

    /** @throws DebitsAndCreditsDoNotEqual */
    private function assertTransactionCreditsEqualDebits(): void
    {
        $credits = 0;
        $debits = 0;

        foreach ($this->transactionsPending as $transaction_pending) {
            if ($transaction_pending['method'] == 'credit') {
                $credits += $transaction_pending['money']->getAmount();
            } else {
                $debits += $transaction_pending['money']->getAmount();
            }
        }

        if ($credits !== $debits) {
            throw new DebitsAndCreditsDoNotEqual('In this transaction, credits == ' . $credits . ' and debits == ' . $debits);
        }
    }
}
