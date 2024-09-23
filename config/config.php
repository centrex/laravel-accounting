<?php

return [
    'base_currency' => 'GBP',

    'model-classes' => [
        'account'             => Centrex\LaravelAccounting\Models\Account::class,
        'ledger'              => Centrex\LaravelAccounting\Models\Ledger::class,
        'journal'             => Centrex\LaravelAccounting\Models\Journal::class,
        'journal-transaction' => Centrex\LaravelAccounting\Models\JournalTransaction::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Available database drivers
    |
    */

    'drivers' => [
        'database' => [
            // 'connection' => 'example',
            'connection' => null,
        ],
    ],
];
