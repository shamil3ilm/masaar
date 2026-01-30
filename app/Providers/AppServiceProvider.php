<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\Services\JwtAuthenticatesUsers;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth: JWT implementation
        $this->app->bind(
            AuthenticatesUsers::class,
            JwtAuthenticatesUsers::class
        );

        // Tenant: Singleton for request-scoped context
        $this->app->singleton(TenantResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
