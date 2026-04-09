<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting;

use Centrex\LaravelAccounting\Commands\{AccountingDemoCommand, AccountingReportCommand};
use Centrex\LaravelAccounting\Events\{InvoicePosted, PaymentRecorded};
use Centrex\LaravelAccounting\Listeners\{NotifyAccountingTeam, SyncCustomerOutstanding};
use Centrex\LaravelAccounting\Livewire\{AccountingDashboard, Bills, ChartOfAccounts, Customers, Expenses, FinancialReports, Invoices, JournalEntries, Vendors};
use Centrex\LaravelAccounting\Models\{Bill, BillItem, Expense, ExpenseItem, Invoice, InvoiceItem, JournalEntry, Payment};
use Centrex\LaravelAccounting\Observers\{BillItemObserver, BillObserver, ExpenseItemObserver, ExpenseObserver, InvoiceItemObserver, InvoiceObserver, JournalEntryObserver, PaymentObserver};
use Illuminate\Support\Facades\{Event, Gate};
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class LaravelAccountingServiceProvider extends ServiceProvider
{
    /** Bootstrap the application services. */
    public function boot(): void
    {
        // Register Livewire Components
        Livewire::component('accounting-dashboard', AccountingDashboard::class);
        Livewire::component('chart-of-accounts', ChartOfAccounts::class);
        Livewire::component('financial-reports', FinancialReports::class);
        Livewire::component('journal-entries', JournalEntries::class);
        Livewire::component('accounting-invoices', Invoices::class);
        Livewire::component('accounting-bills', Bills::class);
        Livewire::component('accounting-expenses', Expenses::class);
        Livewire::component('accounting-customers', Customers::class);
        Livewire::component('accounting-vendors', Vendors::class);

        // Register model observers
        JournalEntry::observe(JournalEntryObserver::class);
        Payment::observe(PaymentObserver::class);
        Invoice::observe(InvoiceObserver::class);
        Bill::observe(BillObserver::class);
        BillItem::observe(BillItemObserver::class);
        InvoiceItem::observe(InvoiceItemObserver::class);
        Expense::observe(ExpenseObserver::class);
        ExpenseItem::observe(ExpenseItemObserver::class);

        // Register event listeners
        Event::listen(InvoicePosted::class, [SyncCustomerOutstanding::class, 'handle']);
        Event::listen(PaymentRecorded::class, [NotifyAccountingTeam::class, 'handle']);

        // Register authorization gates.
        // Host application may override any gate by re-defining it after the provider boots.
        $this->registerGates();

        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'accounting');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'accounting');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('accounting.php'),
            ], 'accounting-config');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/accounting'),
            ], 'accounting-views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/accounting'),
            ], 'accounting-assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/accounting'),
            ], 'accounting-lang');*/

            // Registering package commands.
            $this->commands([
                AccountingReportCommand::class,
                AccountingDemoCommand::class,
            ]);
        }
    }

    /**
     * Register default authorization gates for accounting actions.
     *
     * Each gate falls back to the `accounting-admin` super-gate so host apps
     * can grant blanket access by defining that single gate, or override
     * individual abilities for fine-grained control.
     *
     * Default behaviour: denies everyone (safe default).
     * Override in AppServiceProvider after calling parent::boot().
     */
    protected function registerGates(): void
    {
        $abilities = [
            // Journal entries
            'accounting.journal.view',
            'accounting.journal.create',
            'accounting.journal.post',
            'accounting.journal.void',

            // Invoices & bills
            'accounting.invoice.view',
            'accounting.invoice.create',
            'accounting.invoice.post',
            'accounting.invoice.payment',
            'accounting.bill.view',
            'accounting.bill.create',
            'accounting.bill.post',
            'accounting.bill.payment',

            // Expenses
            'accounting.expense.view',
            'accounting.expense.create',
            'accounting.expense.payment',

            // Reports (read-only)
            'accounting.reports.view',

            // Chart of accounts
            'accounting.accounts.view',
            'accounting.accounts.manage',

            // Customers & vendors
            'accounting.customers.view',
            'accounting.customers.manage',
            'accounting.vendors.view',
            'accounting.vendors.manage',

            // Fiscal year & budgets
            'accounting.fiscal-year.close',
            'accounting.budget.view',
            'accounting.budget.manage',
            'accounting.budget.approve',
        ];

        foreach ($abilities as $ability) {
            // Only define the gate if the host app has not already registered it
            if (! Gate::has($ability)) {
                Gate::define($ability, static function ($user): bool {
                    // Allow if the user passes the super-gate
                    if (Gate::has('accounting-admin') && Gate::forUser($user)->check('accounting-admin')) {
                        return true;
                    }

                    // Fall back to a config-driven role attribute check
                    $roleAttribute = config('accounting.admin_role_attribute', null);
                    if ($roleAttribute && method_exists($user, 'hasRole')) {
                        return $user->hasRole(config('accounting.admin_roles', []));
                    }

                    return false;
                });
            }
        }
    }

    /** Register the application services. */
    public function register(): void
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'accounting');

        // Register the main class to use with the facade
        $this->app->singleton('accounting', fn (): Accounting => new Accounting());
    }
}
