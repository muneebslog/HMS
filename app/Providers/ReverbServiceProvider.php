<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Laravel\Reverb\Console\Commands\InstallCommand;
use Laravel\Reverb\Contracts\Logger;
use Laravel\Reverb\Loggers\NullLogger;
use Laravel\Reverb\Pulse\Livewire;
use Laravel\Reverb\ServerProviderManager;
use Livewire\LivewireManager;

/**
 * App-side Reverb registration that avoids Laravel 13's vendor DevCommands ban.
 *
 * laravel/reverb's package provider calls DevCommands from vendor, which throws
 * on Laravel 13.16+. We dont-discover the package and register it here instead,
 * then call Reverb::registerDevCommands() from AppServiceProvider.
 */
class ReverbServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('vendor/laravel/reverb/config/reverb.php'),
            'reverb'
        );

        $this->app->instance(Logger::class, new NullLogger);

        $this->app->singleton(ServerProviderManager::class);

        $this->app->make(ServerProviderManager::class)->register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(InstallCommand::class);

            $this->publishes([
                base_path('vendor/laravel/reverb/config/reverb.php') => config_path('reverb.php'),
            ], ['reverb', 'reverb-config']);

            if (method_exists($this, 'reloads')) {
                $this->reloads('reverb:restart', 'reverb');
            }
        }

        if ($this->app->bound(Pulse::class)) {
            $this->loadViewsFrom(base_path('vendor/laravel/reverb/resources/views'), 'reverb');

            $this->callAfterResolving('livewire', function (LivewireManager $livewire) {
                $livewire->component('reverb.messages', Livewire\Messages::class);
                $livewire->component('reverb.connections', Livewire\Connections::class);
            });
        }

        $this->app->make(ServerProviderManager::class)->boot();
    }
}
