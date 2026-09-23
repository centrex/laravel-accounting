<?php

declare(strict_types = 1);

namespace Centrex\Accounting\Tests;

use Centrex\Accounting\AccountingServiceProvider;
use Centrex\TallUi\TallUiServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

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

        // Media-library ships its table migration as a vendor:publish stub rather than an
        // auto-discovered migration, so nothing creates it here. Customer and Vendor use
        // HasPrimaryImage and eager-load the media relation, which needs the table to exist.
        $mediaMigrationStub = __DIR__ . '/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';

        if (file_exists($mediaMigrationStub) && !\Illuminate\Support\Facades\Schema::hasTable('media')) {
            (require $mediaMigrationStub)->up();
        }
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            LivewireServiceProvider::class,
            AccountingServiceProvider::class,
        ];

        // Transitive deps of tallui (blade-heroicons -> blade-icons) aren't auto-discovered
        // by WithWorkbench since they're not direct requires of this package; tallui's
        // <x-tallui-icon> renders <x-svg>, which needs BladeIconsServiceProvider's
        // $manifestPath binding, or every route/Livewire test that renders an icon fails.
        if (class_exists(\BladeUI\Icons\BladeIconsServiceProvider::class)) {
            $providers[] = \BladeUI\Icons\BladeIconsServiceProvider::class;
        }

        if (class_exists(\BladeUI\Heroicons\BladeHeroiconsServiceProvider::class)) {
            $providers[] = \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class;
        }

        if (class_exists(\Centrex\Inventory\InventoryServiceProvider::class)) {
            $providers[] = \Centrex\Inventory\InventoryServiceProvider::class;
        }

        // Customer/Vendor use HasPrimaryImage, so any query touching them needs
        // media-library's config (getMediaModel() reads media-library.media_model and is
        // typed to return a string). The provider auto-discovers in a real application —
        // medialibrary is a hard require — but WithWorkbench does not register it here.
        if (class_exists(\Spatie\MediaLibrary\MediaLibraryServiceProvider::class)) {
            $providers[] = \Spatie\MediaLibrary\MediaLibraryServiceProvider::class;
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
