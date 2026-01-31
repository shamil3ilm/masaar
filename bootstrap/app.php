<?php

use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use App\Domains\Compliance\Zatca\Exceptions\CertificateException;
use App\Domains\Compliance\Zatca\Exceptions\SigningException;
use App\Domains\Licensing\Exceptions\LicenseException;
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
        then: function () {
            // Load licensing routes
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/licensing.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'jwt.auth' => \App\Http\Middleware\JwtAuthenticate::class,
            'api.key' => \App\Http\Middleware\ApiKeyAuthenticate::class,
            'rate.api' => \App\Http\Middleware\RateLimitApi::class,
            'license' => \App\Domains\Licensing\Http\Middleware\ValidateLicense::class,
            'license.quota' => \App\Domains\Licensing\Http\Middleware\CheckInvoiceQuota::class,
            'scope' => \App\Domains\Licensing\Http\Middleware\RequireScope::class,
            'env' => \App\Domains\Licensing\Http\Middleware\RequireEnvironment::class,
        ]);

        // Apply CORS to API routes
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle ZATCA-specific exceptions with structured responses
        $exceptions->render(function (ZatcaException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                \Illuminate\Support\Facades\Log::warning('ZATCA Exception', [
                    'code' => $e->getErrorCode()?->value,
                    'message' => $e->getMessage(),
                    'context' => $e->getContext(),
                    'retryable' => $e->isRetryable(),
                ]);

                $response = [
                    'success' => false,
                    'error' => [
                        'code' => $e->getErrorCode()?->value ?? 'ZATCA_ERROR',
                        'message' => $e->getMessage(),
                        'category' => $e->getCategory(),
                        'retryable' => $e->isRetryable(),
                    ],
                ];

                if ($e->isRetryable()) {
                    $response['error']['retry_after'] = $e->getRetryDelay();
                    $response['error']['max_retries'] = $e->getMaxRetries();
                }

                if (!empty($e->getContext())) {
                    $response['error']['context'] = $e->getContext();
                }

                return response()->json($response, $e->getHttpStatusCode());
            }
        });

        // Handle Certificate exceptions
        $exceptions->render(function (CertificateException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                \Illuminate\Support\Facades\Log::error('Certificate Exception', [
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CERTIFICATE_ERROR',
                        'message' => $e->getMessage(),
                        'category' => 'certificate',
                    ],
                ], 400);
            }
        });

        // Handle Signing exceptions
        $exceptions->render(function (SigningException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                \Illuminate\Support\Facades\Log::error('Signing Exception', [
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SIGNING_ERROR',
                        'message' => $e->getMessage(),
                        'category' => 'signing',
                    ],
                ], 400);
            }
        });

        // Handle License exceptions
        $exceptions->render(function (LicenseException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                \Illuminate\Support\Facades\Log::warning('License Exception', [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'context' => $e->context,
                ]);

                $response = [
                    'success' => false,
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                        'category' => 'license',
                    ],
                ];

                if (!empty($e->context)) {
                    $response['error']['context'] = $e->context;
                }

                $httpResponse = response()->json($response, $e->getCode() ?: 403);

                // Add Retry-After header for rate limiting
                if ($e->errorCode === 'LICENSE_RATE_LIMITED' && isset($e->context['retry_after'])) {
                    $httpResponse->header('Retry-After', $e->context['retry_after']);
                }

                return $httpResponse;
            }
        });

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
