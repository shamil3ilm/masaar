<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configurePasswordDefaults();
        $this->configureModels();
        $this->configureDebugSettings();
    }

    /**
     * Configure default password validation rules.
     */
    protected function configurePasswordDefaults(): void
    {
        Password::defaults(function () {
            $rule = Password::min(config('erp.security.password.min_length', 8));

            // Stricter rules for production
            if ($this->app->isProduction()) {
                $rule = $rule
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised();
            }

            return $rule;
        });
    }

    /**
     * Configure Eloquent model behavior.
     */
    protected function configureModels(): void
    {
        // Prevent lazy loading in non-production (catches N+1 issues)
        Model::preventLazyLoading(!$this->app->isProduction());

        // Prevent silently discarding attributes
        Model::preventSilentlyDiscardingAttributes(!$this->app->isProduction());

        // Prevent accessing missing attributes
        Model::preventAccessingMissingAttributes(!$this->app->isProduction());
    }

    /**
     * Configure debug and logging settings.
     */
    protected function configureDebugSettings(): void
    {
        // Log slow queries in production
        if ($this->app->isProduction()) {
            DB::whenQueryingForLongerThan(500, function ($connection, $event) {
                Log::warning('Slow database query detected', [
                    'sql' => $event->sql,
                    'bindings' => $event->bindings,
                    'time' => $event->time,
                    'connection' => $connection->getName(),
                ]);
            });
        }
    }
}
