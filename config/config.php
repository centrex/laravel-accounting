<?php

declare(strict_types = 1);

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
        'accounts'             => 50,
        'journal_entries'      => 15,
        'invoices'             => 15,
        'bills'                => 15,
        'expenses'             => 15,
        'customers'            => 20,
        'vendors'              => 20,
        'loans'                => 15,
        'equity_entries'       => 15,
        'fixed_assets'         => 15,
        'tax_rates'            => 20,
        'bank_reconciliations' => 15,
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
    | Optional Integrations
    |--------------------------------------------------------------------------
    | Accounting stays independent by resolving optional package integrations
    | through contracts instead of importing their concrete models directly.
    */
    'integrations' => [
        'inventory' => [
            'snapshot_provider' => env(
                'ACCOUNTING_INVENTORY_SNAPSHOT_PROVIDER',
                'Centrex\\Inventory\\Support\\AccountingInventorySnapshotProvider',
            ),
            'forecast_service' => env(
                'ACCOUNTING_INVENTORY_FORECAST_SERVICE',
                'Centrex\\Inventory\\Inventory',
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Number Format
    |--------------------------------------------------------------------------
    */
    'number_format' => [
        'decimals'      => 2,
        'decimal_point' => '.',
        'thousands_sep' => ',',
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

    /*
    |--------------------------------------------------------------------------
    | Account Code Mappings
    |--------------------------------------------------------------------------
    | Override any of these to match your chart of accounts. These codes are
    | used by the package when auto-generating journal entries (postInvoice,
    | postBill, recordPayment, postExpense, closeFiscalYear, etc.).
    */
    'accounts' => [
        'cash'                => env('ACCOUNTING_ACCOUNT_CASH', '1000'),
        'bank'                => env('ACCOUNTING_ACCOUNT_BANK', '1100'),
        'accounts_receivable' => env('ACCOUNTING_ACCOUNT_AR', '1200'),
        'inventory'           => env('ACCOUNTING_ACCOUNT_INVENTORY', '1300'),
        'accounts_payable'    => env('ACCOUNTING_ACCOUNT_AP', '2000'),
        // Goods Received Not Invoiced — a distinct liability from Accounts Payable so that
        // capitalizing a goods receipt (before the vendor bill arrives) and later posting that
        // bill don't both land on the same account and inflate it. Must match the inventory
        // package's INVENTORY_ACCOUNTING_GRNI so the two postings net against each other.
        'goods_received_clearing' => env('ACCOUNTING_ACCOUNT_GRNI', '2050'),
        'tax_payable'             => env('ACCOUNTING_ACCOUNT_TAX_PAYABLE', '2300'),
        'retained_earnings'       => env('ACCOUNTING_ACCOUNT_RETAINED_EARNINGS', '3100'),
        'capital'                 => env('ACCOUNTING_ACCOUNT_CAPITAL', '3000'),
        'owner_drawings'          => env('ACCOUNTING_ACCOUNT_OWNER_DRAWINGS', '3200'),
        'sales_revenue'           => env('ACCOUNTING_ACCOUNT_SALES_REVENUE', '4000'),
        'cogs'                    => env('ACCOUNTING_ACCOUNT_COGS', '5000'),
        'purchase_discount'       => env('ACCOUNTING_ACCOUNT_PURCHASE_DISCOUNT', '5500'),
        'purchase_returns'        => env('ACCOUNTING_ACCOUNT_PURCHASE_RETURNS', '5504'),
        'sales_discount'          => env('ACCOUNTING_ACCOUNT_SALES_DISCOUNT', '6130'),
        'sales_returns'           => env('ACCOUNTING_ACCOUNT_SALES_RETURNS', '6134'),
        'delivery_charge'         => env('ACCOUNTING_ACCOUNT_DELIVERY_CHARGE', '6310'),
        'shipping'                => env('ACCOUNTING_ACCOUNT_SHIPPING', '6320'),
        'local_delivery'          => env('ACCOUNTING_ACCOUNT_LOCAL_DELIVERY', '6330'),
        'return_charge'           => env('ACCOUNTING_ACCOUNT_RETURN_CHARGE', '6340'),
        'financing_interest'      => env('ACCOUNTING_ACCOUNT_FINANCING_INTEREST', '6710'),
        'depreciation_expense'    => env('ACCOUNTING_ACCOUNT_DEPRECIATION_EXPENSE', '6600'),
        'gain_loss_on_disposal'   => env('ACCOUNTING_ACCOUNT_GAIN_LOSS_ON_DISPOSAL', '4910'),
        // Pre-selected defaults for the bank reconciliation adjusting-entry mini-form —
        // the user can still pick any active account, these just save a click.
        'bank_fees_expense' => env('ACCOUNTING_ACCOUNT_BANK_FEES_EXPENSE', '6800'),
        'interest_income'   => env('ACCOUNTING_ACCOUNT_INTEREST_INCOME', '4900'),
    ],

];
