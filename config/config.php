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
        'expenses'        => 15,
        'customers'       => 20,
        'vendors'         => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding Tolerance
    |--------------------------------------------------------------------------
    | Maximum allowed difference (in base currency) when comparing balances.
    | Used consistently across trial balance, payment status checks, and
    | fiscal year closure. Default: 0.005 (half a cent).
    */
    'rounding_tolerance' => env('ACCOUNTING_ROUNDING_TOLERANCE', 0.005),

    /*
    |--------------------------------------------------------------------------
    | Period Lock
    |--------------------------------------------------------------------------
    | When true, posting a journal entry whose date falls inside a closed
    | FiscalPeriod throws AccountingException. Set to false to disable the
    | guard (e.g. during data migrations or for legacy back-dating).
    | Internal closing/adjusting entries always bypass this check.
    */
    'enforce_period_lock' => env('ACCOUNTING_ENFORCE_PERIOD_LOCK', true),

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

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | The package registers fine-grained gates for every accounting action.
    | You may grant blanket access by defining the 'accounting-admin' gate in
    | your AppServiceProvider, or configure a role-based check here.
    |
    | admin_roles: list of role names (used with $user->hasRole()) that grant
    | full access to all accounting gates when 'accounting-admin' is not defined.
    |
    | admin_role_attribute: set to any non-null value to enable the hasRole()
    | fallback (e.g. 'roles', 'role'). Leave null to disable.
    */
    'admin_roles'          => env('ACCOUNTING_ADMIN_ROLES', 'accountant,admin'),
    'admin_role_attribute' => env('ACCOUNTING_ADMIN_ROLE_ATTRIBUTE', null),
    'user_foreign_keys'    => env('ACCOUNTING_USER_FOREIGN_KEYS', false),

];
