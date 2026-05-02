<?php

use App\Http\Middleware\AddApiVersionHeader;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CheckBranch;
use App\Http\Middleware\CheckChangeFreeeze;
use App\Http\Middleware\CheckIpAllowlist;
use App\Http\Middleware\CheckFeatureEnabled;
use App\Http\Middleware\CheckIdempotency;
use App\Http\Middleware\CheckModuleEnabled;
use App\Http\Middleware\CheckOrganization;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckSuperAdmin;
use App\Http\Middleware\SimulationMode;
use App\Http\Middleware\TrackResponseTime;
use App\Http\Middleware\TrackUserActivity;
use App\Http\Middleware\ValidateJwtToken;
use App\Http\Middleware\VerifyZatcaWebhook;
use App\Exceptions\ERP\ErpException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;

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
            'super.admin' => CheckSuperAdmin::class,
            'check.idempotency' => CheckIdempotency::class,
            'track.activity' => TrackUserActivity::class,
            'verify.zatca.webhook' => VerifyZatcaWebhook::class,
            'check.change-freeze'    => CheckChangeFreeeze::class,
            'check.ip-allowlist'     => CheckIpAllowlist::class,
            'api.version'            => AddApiVersionHeader::class,
            'track.response.time'    => TrackResponseTime::class,
            'simulation'             => SimulationMode::class,
            'query.budget'           => \App\Http\Middleware\QueryBudget::class,
            'track.impersonation'    => \App\Http\Middleware\TrackImpersonation::class,
        ]);

        // Security headers on every response
        $middleware->append(AddSecurityHeaders::class);

        // Track response time and log slow responses on every request
        $middleware->append(TrackResponseTime::class);

        // Propagate impersonation context from JWT claims to request attributes
        $middleware->append(\App\Http\Middleware\TrackImpersonation::class);

        // Ensure super.admin and validate.jwt run before SubstituteBindings
        // so that authorization checks happen before route model binding
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            CheckSuperAdmin::class,
        );
        $middleware->prependToPriorityList(
            CheckSuperAdmin::class,
            ValidateJwtToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], $e->status);
            }
        });

        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $model = class_basename($e->getModel());
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RESOURCE_NOT_FOUND',
                        'message' => "{$model} not found.",
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 404);
            }
        });

        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $previous = $e->getPrevious();
                if ($previous instanceof ModelNotFoundException) {
                    $model = class_basename($previous->getModel());
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'RESOURCE_NOT_FOUND',
                            'message' => "{$model} not found.",
                        ],
                        'meta' => [
                            'request_id' => (string) Str::uuid(),
                            'timestamp' => now()->toISOString(),
                        ],
                    ], 404);
                }
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'ENDPOINT_NOT_FOUND',
                        'message' => 'The requested endpoint does not exist.',
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 404);
            }
        });

        $exceptions->renderable(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to perform this action.',
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 403);
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Authentication required.',
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 401);
            }
        });

        $exceptions->renderable(function (\InvalidArgumentException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_ARGUMENT',
                        'message' => $e->getMessage(),
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 400);
            }
        });

        $exceptions->renderable(function (ErpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => $e->getErrorCode(),
                        'message' => $e->getMessage(),
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], $e->getHttpStatus());
            }
        });

        $exceptions->renderable(function (QueryException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $errorCode = $e->errorInfo[1] ?? null;
                if ($errorCode === 1062 || $errorCode === 19) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'DUPLICATE_ENTRY',
                            'message' => 'A record with this data already exists.',
                        ],
                        'meta' => [
                            'request_id' => (string) Str::uuid(),
                            'timestamp' => now()->toISOString(),
                        ],
                    ], 409);
                }
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'DATABASE_ERROR',
                        'message' => config('app.debug') ? $e->getMessage() : 'A database error occurred.',
                    ],
                    'meta' => [
                        'request_id' => (string) Str::uuid(),
                        'timestamp' => now()->toISOString(),
                    ],
                ], 500);
            }
        });
    })->create();
