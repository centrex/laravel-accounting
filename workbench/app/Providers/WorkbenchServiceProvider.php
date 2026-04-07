<?php

declare(strict_types = 1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Commands\DemoWorkflowCommand;

class WorkbenchServiceProvider extends ServiceProvider
{
    /** Register services. */
    public function register(): void {}

    /** Bootstrap services. */
    public function boot(): void
    {
        Route::view('/', 'welcome');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DemoWorkflowCommand::class,
            ]);
        }
    }
}
