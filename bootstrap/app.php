<?php

use App\Http\Middleware\CheckBranch;
use App\Http\Middleware\CheckFeatureEnabled;
use App\Http\Middleware\CheckIdempotency;
use App\Http\Middleware\CheckModuleEnabled;
use App\Http\Middleware\CheckOrganization;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\ValidateJwtToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware aliases
        $middleware->alias([
            'check.organization' => CheckOrganization::class,
            'check.permission' => CheckPermission::class,
            'check.branch' => CheckBranch::class,
            'check.module' => CheckModuleEnabled::class,
            'check.feature' => CheckFeatureEnabled::class,
            'validate.jwt' => ValidateJwtToken::class,
            'check.idempotency' => CheckIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
