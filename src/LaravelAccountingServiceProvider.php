<?php

declare(strict_types = 1);

namespace Centrex\LaravelAccounting;

use Illuminate\Support\ServiceProvider;

class LaravelAccountingServiceProvider extends ServiceProvider
{
    /** Bootstrap the application services. */
    public function boot(): void
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'accounting');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'accounting');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

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
            // $this->commands([]);
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
