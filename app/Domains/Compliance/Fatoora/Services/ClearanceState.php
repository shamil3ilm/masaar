<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

/**
 * Reads a ZATCA response and decides what it says about clearance.
 *
 * HTTP 200 does not mean the document is cleared. For a B2B (standard)
 * document only "CLEARED" is terminal — "REPORTED" means ZATCA has the
 * document and has not yet decided. For a simplified (B2C) document the
 * terminal state is "REPORTED" instead. Treating a successful call as a
 * cleared invoice is how a document that ZATCA later rejects gets reported to
 * the taxpayer as accepted.
 */
class ClearanceState
{
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_PENDING_CLEARANCE = 'pending_clearance';

    public const STATE_CONDITIONALLY_ACCEPTED = 'conditionally_accepted';

    public const STATE_CLEARED = 'cleared';

    public const STATE_REPORTED = 'reported';

    public const STATE_REJECTED = 'rejected';

    /**
     * States that need no further checking.
     */
    public const TERMINAL_STATES = [
        self::STATE_CLEARED,
        self::STATE_REPORTED,
        self::STATE_REJECTED,
    ];

    /**
     * @param  array  $zatcaResponse  The response from the ZATCA API
     * @param  bool  $isSimplified  Whether this is a simplified (B2C) invoice
     * @return array{state: string, is_terminal: bool, warnings: array, errors: array}
     */
    public function parseResponse(array $zatcaResponse, bool $isSimplified = false): array
    {
        $clearanceStatus = $zatcaResponse['clearanceStatus'] ?? null;
        $reportingStatus = $zatcaResponse['reportingStatus'] ?? null;
        $validationResults = $zatcaResponse['validationResults'] ?? [];

        $warnings = $this->messages($validationResults['warningMessages'] ?? []);
        $errors = $this->messages($validationResults['errorMessages'] ?? []);

        $state = $this->determineState($clearanceStatus, $reportingStatus, $isSimplified, $errors);

        return [
            'state' => $state,
            'is_terminal' => in_array($state, self::TERMINAL_STATES, true),
            'warnings' => $warnings,
            'errors' => $errors,
            'clearance_status' => $clearanceStatus,
            'reporting_status' => $reportingStatus,
            'invoice_uuid' => $zatcaResponse['invoiceUuid'] ?? null,
        ];
    }

    /**
     * @param  array  $messages  Raw warning or error entries from ZATCA
     */
    private function messages(array $messages): array
    {
        return array_map(
            fn ($message) => [
                'code' => $message['code'] ?? 'UNKNOWN',
                'message' => $message['message'] ?? '',
                'category' => $message['category'] ?? 'general',
            ],
            $messages
        );
    }

    private function determineState(
        ?string $clearanceStatus,
        ?string $reportingStatus,
        bool $isSimplified,
        array $errors
    ): string {
        if (! empty($errors)) {
            return self::STATE_REJECTED;
        }

        $status = $isSimplified ? $reportingStatus : $clearanceStatus;

        $terminal = $isSimplified
            ? ['REPORTED' => self::STATE_REPORTED, 'NOT_REPORTED' => self::STATE_REJECTED]
            : ['CLEARED' => self::STATE_CLEARED, 'NOT_CLEARED' => self::STATE_REJECTED];

        if (isset($terminal[$status])) {
            return $terminal[$status];
        }

        if ($status === 'PENDING') {
            return self::STATE_PENDING_CLEARANCE;
        }

        // A 200 carrying a status nobody recognises. It is not a rejection and
        // not a clearance, so it stays open for another check.
        if ($clearanceStatus || $reportingStatus) {
            return self::STATE_CONDITIONALLY_ACCEPTED;
        }

        return self::STATE_UNKNOWN;
    }
}
