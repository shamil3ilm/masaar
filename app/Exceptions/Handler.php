<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;
use App\Exceptions\ConcurrencyException;
use App\Exceptions\InvalidStateTransitionException;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     */
    protected $levels = [
        // Don't log these at error level
    ];

    /**
     * A list of the exception types that are not reported.
     */
    protected $dontReport = [
        ValidationException::class,
        AuthenticationException::class,
        ModelNotFoundException::class,
        TokenExpiredException::class,
        TokenInvalidException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Add custom reporting here (Sentry, etc.)
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return $this->handleApiException($e, $request);
            }
        });
    }

    /**
     * Handle API exceptions with consistent JSON response
     */
    protected function handleApiException(Throwable $e, Request $request): JsonResponse
    {
        $requestId = (string) Str::uuid();

        // Log the full exception for debugging
        if ($this->shouldReport($e)) {
            \Log::error('API Exception', [
                'request_id' => $requestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_id' => auth()->id(),
                // Don't log sensitive input
                'input_keys' => array_keys($request->except($this->dontFlash)),
            ]);
        }

        return match (true) {
            $e instanceof ValidationException => $this->validationResponse($e, $requestId),
            $e instanceof AuthenticationException => $this->authResponse($e, $requestId),
            $e instanceof TokenExpiredException => $this->tokenExpiredResponse($requestId),
            $e instanceof TokenInvalidException, $e instanceof JWTException => $this->tokenInvalidResponse($requestId),
            $e instanceof ConcurrencyException => $this->concurrencyResponse($e, $requestId),
            $e instanceof InvalidStateTransitionException => $this->stateTransitionResponse($e, $requestId),
            $e instanceof ModelNotFoundException => $this->notFoundResponse($e, $requestId),
            $e instanceof NotFoundHttpException => $this->routeNotFoundResponse($requestId),
            $e instanceof MethodNotAllowedHttpException => $this->methodNotAllowedResponse($e, $requestId),
            $e instanceof AccessDeniedHttpException => $this->forbiddenResponse($requestId),
            $e instanceof TooManyRequestsHttpException => $this->rateLimitResponse($e, $requestId),
            $e instanceof QueryException => $this->databaseErrorResponse($e, $requestId),
            default => $this->serverErrorResponse($e, $requestId),
        };
    }

    protected function validationResponse(ValidationException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'The given data was invalid.',
                'details' => $e->errors(),
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 422);
    }

    protected function authResponse(AuthenticationException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 401);
    }

    protected function tokenExpiredResponse(string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'TOKEN_EXPIRED',
                'message' => 'Token has expired.',
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 401);
    }

    protected function tokenInvalidResponse(string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'TOKEN_INVALID',
                'message' => 'Token is invalid.',
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 401);
    }

    protected function notFoundResponse(ModelNotFoundException $e, string $requestId): JsonResponse
    {
        $model = class_basename($e->getModel());

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'RESOURCE_NOT_FOUND',
                'message' => "{$model} not found.",
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 404);
    }

    protected function routeNotFoundResponse(string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'ENDPOINT_NOT_FOUND',
                'message' => 'The requested endpoint does not exist.',
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 404);
    }

    protected function methodNotAllowedResponse(MethodNotAllowedHttpException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'The requested HTTP method is not allowed for this endpoint.',
                'allowed_methods' => $e->getHeaders()['Allow'] ?? null,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 405);
    }

    protected function forbiddenResponse(string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'You do not have permission to perform this action.',
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 403);
    }

    protected function concurrencyResponse(ConcurrencyException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'CONCURRENCY_CONFLICT',
                'message' => $e->getMessage(),
                'current_version' => $e->getCurrentVersion(),
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 409);
    }

    protected function stateTransitionResponse(InvalidStateTransitionException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'INVALID_STATE_TRANSITION',
                'message' => $e->getMessage(),
                'current_state' => $e->currentState,
                'attempted_state' => $e->attemptedState,
                'allowed_states' => $e->allowedStates,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 422);
    }

    protected function rateLimitResponse(TooManyRequestsHttpException $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 429);
    }

    protected function databaseErrorResponse(QueryException $e, string $requestId): JsonResponse
    {
        // Handle specific database errors
        $errorCode = $e->errorInfo[1] ?? null;

        // Duplicate entry
        if ($errorCode === 1062) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DUPLICATE_ENTRY',
                    'message' => 'A record with this data already exists.',
                ],
                'meta' => [
                    'request_id' => $requestId,
                    'timestamp' => now()->toISOString(),
                ],
            ], 409);
        }

        // Foreign key constraint
        if ($errorCode === 1451 || $errorCode === 1452) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REFERENCE_CONSTRAINT',
                    'message' => 'This record cannot be modified because it is referenced by other records.',
                ],
                'meta' => [
                    'request_id' => $requestId,
                    'timestamp' => now()->toISOString(),
                ],
            ], 409);
        }

        // Generic database error (don't expose details in production)
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'DATABASE_ERROR',
                'message' => 'A database error occurred.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 500);
    }

    protected function serverErrorResponse(Throwable $e, string $requestId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'SERVER_ERROR',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.',
                'details' => config('app.debug') ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ] : null,
            ],
            'meta' => [
                'request_id' => $requestId,
                'timestamp' => now()->toISOString(),
            ],
        ], 500);
    }
}
