<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Currency
    |--------------------------------------------------------------------------
    */
    'base_currency' => env('ACCOUNTING_CURRENCY', 'BDT'),

    /*
    |--------------------------------------------------------------------------
    | Database Driver Configurations
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'database' => [
            'connection' => env('ACCOUNTING_DB_CONNECTION'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    */
    'table_prefix' => env('ACCOUNTING_TABLE_PREFIX', 'acct_'),

    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    | Middleware and prefix for the Livewire/web accounting pages.
    */
    'web_middleware' => ['web', 'auth'],
    'web_prefix'     => 'accounting',

    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    | Middleware and prefix for the REST API endpoints.
    | Set api_middleware to [] to disable authentication for the API.
    */
    'api_middleware' => ['api', 'auth:sanctum'],
    'api_prefix'     => 'api/accounting',

    /*
    |--------------------------------------------------------------------------
    | Fiscal Year Settings
    |--------------------------------------------------------------------------
    */
    'fiscal_year' => [
        'start_month' => env('ACCOUNTING_FISCAL_START_MONTH', 1), // January
        'auto_create' => env('ACCOUNTING_FISCAL_AUTO_CREATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'per_page' => [
        'accounts'        => 50,
        'journal_entries' => 15,
        'invoices'        => 15,
        'bills'           => 15,
        'customers'       => 20,
        'vendors'         => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Number Format
    |--------------------------------------------------------------------------
    */
    'number_format' => [
        'decimals'        => 2,
        'decimal_point'   => '.',
        'thousands_sep'   => ',',
    ],

];
