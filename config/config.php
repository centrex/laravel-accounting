<?php

return [
    'base_currency' => 'BDT',

    'model-classes' => [
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
