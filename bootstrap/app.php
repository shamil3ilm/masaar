<?php

use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Exceptions\SigningException;
use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Http\Middleware\PlatformLicense;
use App\Domains\Platform\Http\Middleware\LogContext;
use App\Domains\Platform\Http\Middleware\SecurityHeaders;
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
        // Every response, web and API alike: a PHP version is announced on
        // both, and the rest cost nothing on a JSON response.
        $middleware->append(SecurityHeaders::class);

        $middleware->api(prepend: [
            HandleCors::class,
            // First, so a request refused by anything after it is still
            // identifiable in the log - including a 429 from the limiter and a
            // licence refusal, which are the two a customer is most likely to
            // ask about. Safe here, unlike a limiter: it counts nothing.
            //
            // RateLimitApi is deliberately NOT in this group. It was, briefly,
            // and that was wrong twice over. routes/api.php splits the API by
            // audience so each file declares its own stack once at the top,
            // and rate.api was already on tenant.php, partner.php and
            // platform.php - so a group entry ran it a second time per
            // request. Worse, the group runs before jwt.auth, so that second
            // run saw no user, drew on the anonymous budget of twenty a
            // minute, and would have capped the whole authenticated API at
            // twenty requests a minute per address. public.php was the one
            // file that lacked it, and that is where it belongs.
            LogContext::class,
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

                return ApiResponse::fromFatoora($e);
            }
        });

        // Handle Certificate exceptions
        $exceptions->render(function (CertificateException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                Log::error('Certificate Exception', [
                    'message' => $e->getMessage(),
                ]);

                // 422, not the 400 this answered. A certificate that is
                // expired, revoked or mismatched is a well-formed request
                // refused by its credential, which is what the taxonomy says
                // too: every CERT_* content failure is a 422 and the missing
                // ones are 404. A 400 described it as unreadable syntax.
                return ApiResponse::fromCertificate($e);
            }
        });

        // Handle Signing exceptions
        $exceptions->render(function (SigningException $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                Log::error('Signing Exception', [
                    'message' => $e->getMessage(),
                ]);

                // 500, not the 400 this answered. Signing happens entirely
                // on this side, after the request has been accepted and
                // validated, so there is no request the caller could send
                // that avoids it. Telling them it was a bad request sends
                // them to look at their own payload for our defect.
                return ApiResponse::error($e->getMessage(), 500, [], 'SIGNING_ERROR', 'signing');
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

                // Retry-After is no longer conditional on one error code
                // string. The envelope attaches it whenever the status is one
                // that invites a retry, so a licence failure that starts
                // answering 503 gets the header without anyone remembering.
                return ApiResponse::error(
                    $e->getMessage(),
                    $e->getCode() ?: 403,
                    is_array($e->context) ? $e->context : [],
                    $e->errorCode,
                    'license',
                    isset($e->context['retry_after']) ? (int) $e->context['retry_after'] : null,
                );
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
                    $e->getMessage() ?: 'The request could not be completed',
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
