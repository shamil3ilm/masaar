<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\DTOs;

final readonly class FtaResponse
{
    public function __construct(
        public bool $success,
        public string $status,              // accepted|pending_review|rejected|failed
        public ?string $submissionId,       // FTA-assigned reference
        public ?string $validationStatus,   // PASS|WARNING|ERROR
        public array $warnings,
        public array $errors,
        public ?string $rawResponse,
    ) {}

    public static function fromApiResponse(array $response): self
    {
        $status = $response['status'] ?? 'failed';

        return new self(
            success: $status === 'accepted',
            status: $status,
            submissionId: $response['submissionId'] ?? null,
            validationStatus: $response['validationStatus'] ?? null,
            warnings: $response['warnings'] ?? [],
            errors: $response['errors'] ?? [],
            rawResponse: json_encode($response),
        );
    }

    public static function failed(string $error, ?string $raw = null): self
    {
        return new self(
            success: false,
            status: 'failed',
            submissionId: null,
            validationStatus: 'ERROR',
            warnings: [],
            errors: [$error],
            rawResponse: $raw,
        );
    }
}
