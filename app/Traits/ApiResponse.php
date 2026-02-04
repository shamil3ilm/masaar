<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

trait ApiResponse
{
    protected function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $this->getMeta(),
        ];

        return response()->json($response, $statusCode);
    }

    protected function created(
        mixed $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return $this->success($data, $message, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function error(
        string $message,
        string $code = 'ERROR',
        int $statusCode = 400,
        array $details = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => $this->getMeta(),
        ];

        if (!empty($details)) {
            $response['error']['details'] = $details;
        }

        return response()->json($response, $statusCode);
    }

    protected function validationError(array $errors): JsonResponse
    {
        return $this->error(
            message: 'The given data was invalid.',
            code: 'VALIDATION_ERROR',
            statusCode: 422,
            details: $errors
        );
    }

    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error($message, 'UNAUTHORIZED', 401);
    }

    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->error($message, 'FORBIDDEN', 403);
    }

    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, 'NOT_FOUND', 404);
    }

    protected function serverError(string $message = 'An error occurred'): JsonResponse
    {
        return $this->error($message, 'SERVER_ERROR', 500);
    }

    /**
     * Return error response using predefined error code from ErrorCodes class.
     */
    protected function errorFromCode(
        array $errorCode,
        ?array $details = null,
        ?string $customMessage = null
    ): JsonResponse {
        return $this->error(
            message: $customMessage ?? $errorCode['message'],
            code: $errorCode['code'],
            statusCode: $errorCode['http_status'],
            details: $details ?? []
        );
    }

    /**
     * Throw an ApiException using predefined error code.
     */
    protected function throwError(
        array $errorCode,
        ?array $details = null,
        ?string $customMessage = null
    ): never {
        throw \App\Exceptions\ApiException::fromError($errorCode, $details, $customMessage);
    }

    protected function resource(JsonResource $resource, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resource,
            'meta' => $this->getMeta(),
        ]);
    }

    protected function collection(
        ResourceCollection $collection,
        string $message = 'Success'
    ): JsonResponse {
        $paginated = $collection->response()->getData(true);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginated['data'],
            'meta' => array_merge(
                $this->getMeta(),
                $paginated['meta'] ?? [],
            ),
            'links' => $paginated['links'] ?? null,
        ]);
    }

    protected function paginated(
        $paginator,
        string $resourceClass = null,
        string $message = 'Success'
    ): JsonResponse {
        $data = $paginator->items();

        if ($resourceClass) {
            $data = $resourceClass::collection($data);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge($this->getMeta(), [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    private function getMeta(): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
