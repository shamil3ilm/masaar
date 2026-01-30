<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\Services\JwtAuthenticatesUsers;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            AuthenticatesUsers::class,
            JwtAuthenticatesUsers::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
