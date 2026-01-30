<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'jwt.auth' => \App\Http\Middleware\JwtAuthenticate::class,
            'api.key' => \App\Http\Middleware\ApiKeyAuthenticate::class,
            'rate.api' => \App\Http\Middleware\RateLimitApi::class,
        ]);

        // Apply CORS to API routes
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle API exceptions with JSON responses
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                return ApiResponse::notFound('Resource not found');
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                return ApiResponse::validationError($e->errors());
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                return ApiResponse::error(
                    $e->getMessage() ?: 'An error occurred',
                    $e->getStatusCode()
                );
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                // Log the error for debugging
                \Illuminate\Support\Facades\Log::error('API Error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Return generic error in production, detailed in debug mode
                $message = config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred';

                return ApiResponse::error($message, 500);
            }
        });
    })->create();
