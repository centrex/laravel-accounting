<?php

/*
 * You can place your custom package configuration in here.
 */
return [
    'base_currency' => 'GBP',

    'model-classes' => [
        'ledger' => \Centrex\LaravelAccounting\Models\Ledger::class,
        'journal' => \Centrex\LaravelAccounting\Models\Journal::class,
        'journal-transaction' => \Centrex\LaravelAccounting\Models\JournalTransaction::class,
    ],
];