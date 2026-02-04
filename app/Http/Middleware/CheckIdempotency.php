<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIdempotency
{
    private const HEADER_NAME = 'X-Idempotency-Key';

    public function __construct(
        private IdempotencyService $idempotencyService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only apply to mutating requests
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        // Check for idempotency key header
        $idempotencyKey = $request->header(self::HEADER_NAME);
        if (!$idempotencyKey) {
            // No key provided, proceed normally
            return $next($request);
        }

        // Validate key format
        if (!$this->isValidKey($idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_IDEMPOTENCY_KEY',
                    'message' => 'Idempotency key must be a valid UUID or string (max 64 chars)',
                ],
            ], 400);
        }

        $userId = auth()->id();
        if (!$userId) {
            // No authenticated user, proceed normally
            return $next($request);
        }

        // Check for existing response
        $existingResponse = $this->idempotencyService->getExistingResponse($idempotencyKey, $userId);
        if ($existingResponse) {
            return $existingResponse;
        }

        // Try to acquire lock (prevents race conditions)
        if (!$this->idempotencyService->acquireLock($idempotencyKey, $userId)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_IN_PROGRESS',
                    'message' => 'A request with this idempotency key is already being processed',
                ],
            ], 409);
        }

        try {
            // Process the request
            $response = $next($request);

            // Store the response if it's a JsonResponse
            if ($response instanceof JsonResponse) {
                $this->idempotencyService->storeResponse(
                    $idempotencyKey,
                    $userId,
                    $request->path(),
                    $response
                );
            }

            return $response;
        } finally {
            // Always release the lock
            $this->idempotencyService->releaseLock($idempotencyKey, $userId);
        }
    }

    private function isValidKey(string $key): bool
    {
        return strlen($key) <= 64 && strlen($key) >= 8;
    }
}
