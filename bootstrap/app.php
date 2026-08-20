<?php

use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Exceptions\SigningException;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Http\Middleware\PlatformLicense;
use App\Http\Responses\ApiResponse;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
            Route::middleware('api')
                ->group(base_path('routes/licensing.php'));
        },
    )
    // Laravel only auto-discovers commands in app/Console/Commands. Domains
    // that own console commands must be listed, or their commands are never
    // registered and anything scheduling them fails silently.
    ->withCommands([
        __DIR__.'/../app/Domains/Licensing/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Single source of truth. AppServiceProvider::boot() reasserts these
        // after package providers boot, because a package can otherwise claim
        // an alias declared here.
        $middleware->alias(AppServiceProvider::MIDDLEWARE_ALIASES);

        // Blade consoles authenticate with a session; send guests to the form.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Apply CORS to API routes, and gate them on the platform licence.
        //
        // PlatformLicense was written, aliased in AppServiceProvider, and
        // attached to nothing — so the commercial gate for the product itself
        // never ran on a single request. It belongs on the group rather than in
        // each audience's route file: it answers "may this deployment serve at
        // all", which is not a question about who is calling.
        //
        // It self-skips when platform-license.enabled is false and for the
        // health and licence-status paths, so a deployment that has not been
        // issued a key is not locked out of its own health check.
        $middleware->api(prepend: [
            HandleCors::class,
            PlatformLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle ZATCA-specific exceptions with structured responses
        $exceptions->render(function (FatooraException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                Log::warning('ZATCA Exception', [
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

                if (! empty($e->getContext())) {
                    $response['error']['context'] = $e->getContext();
                }

                return response()->json($response, $e->getHttpStatusCode());
            }
        });

        // Handle Certificate exceptions
        $exceptions->render(function (CertificateException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                Log::error('Certificate Exception', [
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
                Log::error('Signing Exception', [
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
                Log::warning('License Exception', [
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

                if (! empty($e->context)) {
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

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                // Log the error for debugging
                Log::error('API Error', [
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
