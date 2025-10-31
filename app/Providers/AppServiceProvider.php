<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS when behind a proxy (like ngrok) or in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Check for X-Forwarded-Proto header from proxy
        if (request()->header('X-Forwarded-Proto') === 'https' ||
            request()->header('X-Forwarded-Ssl') === 'on' ||
            request()->secure()) {
            URL::forceScheme('https');
        }
    }
}
