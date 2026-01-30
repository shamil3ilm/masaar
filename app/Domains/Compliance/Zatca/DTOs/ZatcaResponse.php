<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * ZATCA API response wrapper.
 */
final readonly class ZatcaResponse
{
    public function __construct(
        public bool $success,
        public ?string $clearanceStatus,    // CLEARED, REPORTED, NOT_CLEARED
        public ?string $reportingStatus,
        public ?string $validationStatus,   // PASS, WARNING, ERROR
        public ?string $clearedInvoice,     // Base64 signed invoice XML
        public array $validationResults,
        public array $warningMessages,
        public array $errorMessages,
        public ?string $rawResponse,
    ) {}

    /**
     * Create from ZATCA API response.
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            success: ($response['clearanceStatus'] ?? null) === 'CLEARED'
                || ($response['reportingStatus'] ?? null) === 'REPORTED',
            clearanceStatus: $response['clearanceStatus'] ?? null,
            reportingStatus: $response['reportingStatus'] ?? null,
            validationStatus: $response['validationResults']['status'] ?? null,
            clearedInvoice: $response['clearedInvoice'] ?? null,
            validationResults: $response['validationResults'] ?? [],
            warningMessages: $response['warningMessages'] ?? [],
            errorMessages: $response['errorMessages'] ?? [],
            rawResponse: json_encode($response),
        );
    }

    /**
     * Create failed response.
     */
    public static function failed(string $error, ?string $rawResponse = null): self
    {
        return new self(
            success: false,
            clearanceStatus: 'NOT_CLEARED',
            reportingStatus: null,
            validationStatus: 'ERROR',
            clearedInvoice: null,
            validationResults: [],
            warningMessages: [],
            errorMessages: [$error],
            rawResponse: $rawResponse,
        );
    }

    public function hasWarnings(): bool
    {
        return count($this->warningMessages) > 0;
    }

    public function hasErrors(): bool
    {
        return count($this->errorMessages) > 0;
    }
}
