<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting\Tests;

use Centrex\LaravelAccounting\LaravelAccountingServiceProvider;
use Centrex\LaravelAccounting\Models\Ledger;
use Faker\Factory as Faker;
use Models\{Account, CompanyJournal, User};
use Money\Currency;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected $currency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currency = new Currency('USD');

        $this->requireFilesIn(__DIR__ . '/Models');

        $this->artisan('migrate', ['--database' => 'testbench', '--path' => 'migrations']);
        $this->loadMigrationsFrom(realpath(__DIR__ . '/migrations'));

        $this->faker = Faker::create();
        $this->setUpCompanyLedgersAndJournals();
    }

    /**
     * When using PHP Storm,
     *
     * @param null $directory
     */
    public function requireFilesIn($directory = null)
    {
        if ($directory) {
            foreach (scandir($directory) as $filename) {
                $file_path = $directory . '/' . $filename;

                if (is_file($file_path)) {
                    require_once $file_path;
                }
            }
        }
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelAccountingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        Eloquent::unguard();
    }

    /**
     * @return array
     */
    protected function createFakeUsers(int $qty)
    {
        $users = [];

        for ($x = 1; $x <= $qty; $x++) {
            $users[] = $this->createFakeUser();
        }

        return $users;
    }

    /**
     * @return array
     */
    protected function createFakeUser()
    {
        return User::create([
            'name'     => $this->faker->name,
            'email'    => $this->faker->email,
            'password' => $this->faker->password,
        ]);
    }

    /**
     * @return array
     */
    protected function createFakeAccount()
    {
        return Account::create([
            'name' => $this->faker->company,
        ]);
    }

    protected function setUpCompanyLedgersAndJournals()
    {
        /*
        |--------------------------------------------------------------------------
        | These would probably be pretty standard
        |--------------------------------------------------------------------------
        */

        $this->company_assets_ledger = Ledger::create([
            'name' => 'Company Assets',
            'type' => 'asset',
        ]);

        $this->company_liability_ledger = Ledger::create([
            'name' => 'Company Liabilities',
            'type' => 'liability',
        ]);

        $this->company_equity_ledger = Ledger::create([
            'name' => 'Company Equity',
            'type' => 'equity',
        ]);

        $this->company_income_ledger = Ledger::create([
            'name' => 'Company Income',
            'type' => 'income',
        ]);

        $this->company_expense_ledger = Ledger::create([
            'name' => 'Company Expenses',
            'type' => 'expense',
        ]);

        /*
        |--------------------------------------------------------------------------
        | This can be a bit confusing, becasue we are creating a new "company journal"
        | Really this is just a table with a bunch of obects that we attach journals to
        | for the company.
        |--------------------------------------------------------------------------
        */

        $this->company_ar_journal = CompanyJournal::create(['name' => 'Accounts Receivable'])->initJournal();
        $this->company_ar_journal->assignToLedger($this->company_assets_ledger);

        $this->company_cash_journal = CompanyJournal::create(['name' => 'Cash'])->initJournal();
        $this->company_cash_journal->assignToLedger($this->company_assets_ledger);

        $this->company_income_journal = CompanyJournal::create(['name' => 'Company Income'])->initJournal();
        $this->company_income_journal->assignToLedger($this->company_income_ledger);
    }
}
