<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Tests;

use Centrex\Accounting\AccountingServiceProvider;
use Centrex\TallUi\TallUiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithWorkbench]
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            function (string $modelName): string {
                $namespace = str_starts_with($modelName, 'Centrex\\Inventory\\')
                    ? 'Centrex\\Inventory\\Database\\Factories\\'
                    : 'Centrex\\Accounting\\Database\\Factories\\';

                return $namespace . class_basename($modelName) . 'Factory';
            },
        );

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            AccountingServiceProvider::class,
        ];

        if (class_exists(\Centrex\Inventory\InventoryServiceProvider::class)) {
            $providers[] = \Centrex\Inventory\InventoryServiceProvider::class;
        }

        if (class_exists(TallUiServiceProvider::class)) {
            $providers[] = TallUiServiceProvider::class;
        }

        return $providers;
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        config()->set('accounting.web_middleware', ['web']);
        config()->set('accounting.api_middleware', ['api']);
        config()->set('inventory.web_middleware', ['web']);
        config()->set('inventory.api_middleware', ['api']);
    }
}
