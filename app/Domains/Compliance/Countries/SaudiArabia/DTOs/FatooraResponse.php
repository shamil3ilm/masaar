<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\DTOs;

use App\Support\Errors\ErrorCode;

/**
 * ZATCA API response wrapper.
 */
final readonly class FatooraResponse
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
        /**
         * Why it failed, where that is known.
         *
         * A failure was a string in errorMessages and nothing else, so a
         * caller could not tell the authority refusing a document from the
         * authority declining to look at it yet.
         */
        public ?ErrorCode $errorCode = null,
        /** Seconds to wait before retrying, when the authority said. */
        public ?int $retryAfterSeconds = null,
        /**
         * Whether the request may have reached the authority.
         *
         * False only when it certainly did not: the connection never opened,
         * or the authority said it was too busy to look. A read timeout or a
         * 5xx after the request went out could follow a clearance ZATCA has
         * already granted, and sending a clearance again files it twice.
         */
        public bool $mayHaveArrived = true,
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
            // ZATCA puts its advice under validationResults (the Reporting
            // and Clearance APIs answer 202 with it); read from the top level
            // only, every warning was lost.
            warningMessages: self::messages([
                ...(array) ($response['validationResults']['warningMessages'] ?? []),
                ...(array) ($response['warningMessages'] ?? []),
            ]),
            errorMessages: $response['errorMessages'] ?? [],
            rawResponse: json_encode($response),
        );
    }

    /**
     * The authority's messages as a person and an integrator read them:
     * "BR-KSA-F-13: what it says", its rule code first. Text already in
     * that shape is kept, and nothing is dropped.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<string>
     */
    public static function messages(array $entries): array
    {
        $messages = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $code = trim((string) ($entry['code'] ?? ''));
                $text = trim((string) ($entry['message'] ?? ''));
                $entry = $code !== '' && $text !== '' ? $code.': '.$text : ($text !== '' ? $text : ($code !== '' ? $code : (string) json_encode($entry)));
            }

            if (is_scalar($entry) && trim((string) $entry) !== '') {
                $messages[] = trim((string) $entry);
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Create failed response.
     */
    public static function failed(
        string $error,
        ?string $rawResponse = null,
        ?ErrorCode $errorCode = null,
        ?int $retryAfterSeconds = null,
        bool $mayHaveArrived = true,
    ): self {
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
            errorCode: $errorCode,
            retryAfterSeconds: $retryAfterSeconds,
            mayHaveArrived: $mayHaveArrived,
        );
    }

    /**
     * The authority declined to look at this document yet.
     *
     * Distinct from a refusal: nothing about the document is wrong, and
     * recording it as rejected would say the opposite in the one place an
     * operator looks.
     */
    public static function throttled(int $retryAfterSeconds, ?string $rawResponse = null): self
    {
        return self::failed(
            "ZATCA is rate limiting this device; retry in {$retryAfterSeconds}s",
            $rawResponse,
            ErrorCode::ZATCA_RATE_LIMITED,
            $retryAfterSeconds,
            mayHaveArrived: false,
        );
    }

    /** Whether waiting and trying again is the right response. */
    public function isRetryable(): bool
    {
        return $this->errorCode?->isRetryable() ?? false;
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
