<?php

declare(strict_types = 1);

return [
    'base_currency' => 'GBP',

    'model-classes' => [
        'ledger'              => \Centrex\LaravelAccounting\Models\Ledger::class,
        'journal'             => \Centrex\LaravelAccounting\Models\Journal::class,
        'journal-transaction' => \Centrex\LaravelAccounting\Models\JournalTransaction::class,
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

    /*
    |--------------------------------------------------------------------------
    | Queue Configurations
    |--------------------------------------------------------------------------
    |
    | Available queue configurations.
    |
    */

    'queue' => [
        'enable'     => true,
        'connection' => 'sync',
        'queue'      => 'default',
        'delay'      => 0,
    ],
];
