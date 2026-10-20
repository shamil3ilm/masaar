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
        /**
         * Whether this is the authority's judgement of the document.
         *
         * Only an answer is: a 2xx, or a 400 that says what the document
         * broke (Detailed Guidelines, "Overview of responses and required
         * actions": 400 "Action failed (rejected)"). A 401, 413, 5xx, a
         * timeout or an exception of ours judges nothing, and must never
         * mark an invoice Rejected.
         */
        public bool $verdict = true,
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
     * The authority refusing the document: HTTP 400 and the other refusal
     * codes, with its reasons under validationResults - each error and
     * warning with its rule code, category and message (ZATCA Developer
     * Portal, Reporting and Clearance APIs). Kept whole, where a summary
     * string alone used to survive.
     *
     * @param  array<string, mixed>  $body
     */
    public static function refused(string $summary, array $body, ?string $rawResponse = null): self
    {
        $validation = (array) ($body['validationResults'] ?? []);
        $named = [...(array) ($validation['errorMessages'] ?? []), ...array_filter((array) ($body['errorMessages'] ?? []), 'is_array')];

        return new self(
            success: false,
            clearanceStatus: $body['clearanceStatus'] ?? 'NOT_CLEARED',
            reportingStatus: $body['reportingStatus'] ?? null,
            validationStatus: $validation['status'] ?? 'ERROR',
            clearedInvoice: null,
            validationResults: [
                ...$validation,
                // An error the authority put at the top level - as some
                // refusals word it - counts with the rest.
                'errorMessages' => $named,
            ],
            warningMessages: self::messages([...(array) ($validation['warningMessages'] ?? []), ...(array) ($body['warningMessages'] ?? [])]),
            errorMessages: [$summary],
            rawResponse: $rawResponse,
            verdict: $named !== []
                || ($body['clearanceStatus'] ?? null) === 'NOT_CLEARED'
                || ($body['reportingStatus'] ?? null) === 'NOT_REPORTED',
        );
    }

    /**
     * The same answer, held under $code as no verdict: an authority reply
     * that judged nothing (a 400 naming no rule, a 401, a 413), kept with
     * its reason and never written as Rejected.
     */
    public function withCode(ErrorCode $code): self
    {
        return new self(
            success: false,
            clearanceStatus: $this->clearanceStatus,
            reportingStatus: $this->reportingStatus,
            validationStatus: $this->validationStatus,
            clearedInvoice: null,
            validationResults: $this->validationResults,
            warningMessages: $this->warningMessages,
            errorMessages: $this->errorMessages,
            rawResponse: $this->rawResponse,
            errorCode: $code,
            mayHaveArrived: false,
            verdict: false,
        );
    }

    /**
     * Each rule the authority said the document broke: code, category and
     * message, as validationResults.errorMessages gives them; the summary
     * alone, without a code, when it named none.
     *
     * @return list<array{code: ?string, category: ?string, message: string}>
     */
    public function errorDetails(): array
    {
        return self::details((array) ($this->validationResults['errorMessages'] ?? [])) ?: self::details($this->errorMessages);
    }

    /**
     * Each warning it accepted or refused the document with, in the same shape.
     *
     * @return list<array{code: ?string, category: ?string, message: string}>
     */
    public function warningDetails(): array
    {
        return self::details((array) ($this->validationResults['warningMessages'] ?? [])) ?: self::details($this->warningMessages);
    }

    /**
     * Messages as kept and shown: code, category and message. An entry
     * already of "CODE: message" text gives its code back.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<array{code: ?string, category: ?string, message: string}>
     */
    public static function details(array $entries): array
    {
        $details = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $code = trim((string) ($entry['code'] ?? ''));
                $category = trim((string) ($entry['category'] ?? ''));
                $message = trim((string) ($entry['message'] ?? ''));
            } elseif (is_scalar($entry) && trim((string) $entry) !== '') {
                [$code, $message] = preg_match('/^([A-Z][A-Za-z0-9_-]*-[A-Za-z0-9_-]+): (.+)$/s', trim((string) $entry), $parts) === 1
                    ? [$parts[1], $parts[2]]
                    : ['', trim((string) $entry)];
                $category = '';
            } else {
                continue;
            }

            if ($code === '' && $message === '') {
                continue;
            }

            $details[] = ['code' => $code === '' ? null : $code, 'category' => $category === '' ? null : $category, 'message' => $message];
        }

        return array_values(array_unique($details, SORT_REGULAR));
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
            verdict: false,
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

    /** Whether the authority judged the document; see $verdict. */
    public function isVerdict(): bool
    {
        return $this->success || $this->verdict;
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
