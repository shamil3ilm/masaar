<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

final readonly class SubmissionResult
{
    public function __construct(
        public bool $success,
        public ?string $submissionId,
        public ?string $referenceId,
        public string $status,
        public array $rawResponse,
        public ?string $errorMessage,
    ) {}

    public static function failure(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            submissionId: null,
            referenceId: null,
            status: 'failed',
            rawResponse: $rawResponse,
            errorMessage: $errorMessage,
        );
    }
}
