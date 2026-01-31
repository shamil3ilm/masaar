<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Enums\ErrorCode;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clearance State Manager - Handle ZATCA partial success scenarios.
 *
 * PROBLEM: ZATCA returns HTTP 200 but the invoice isn't fully cleared.
 * - "REPORTED" (async) means "we received it, we'll process later"
 * - Only "CLEARED" is the terminal success state for B2B
 * - Only "REPORTED" is terminal for simplified (B2C)
 *
 * This service:
 * - Tracks clearance state separately from submission status
 * - Schedules re-checks for non-terminal states
 * - Handles timeout scenarios
 * - Provides audit trail for compliance
 */
class ClearanceStateManager
{
    /**
     * Clearance states.
     */
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_PENDING_CLEARANCE = 'pending_clearance';
    public const STATE_CONDITIONALLY_ACCEPTED = 'conditionally_accepted';
    public const STATE_CLEARED = 'cleared';
    public const STATE_REPORTED = 'reported';
    public const STATE_REJECTED = 'rejected';
    public const STATE_TIMEOUT = 'timeout';

    /**
     * Terminal states (no more checks needed).
     */
    public const TERMINAL_STATES = [
        self::STATE_CLEARED,
        self::STATE_REPORTED,
        self::STATE_REJECTED,
    ];

    /**
     * Get max check attempts from config.
     */
    private function getMaxCheckAttempts(): int
    {
        return (int) config('zatca.clearance_state.max_check_attempts', 10);
    }

    /**
     * Get initial check delay from config.
     */
    private function getInitialCheckDelay(): int
    {
        return (int) config('zatca.clearance_state.initial_check_delay_seconds', 30);
    }

    /**
     * Get maximum check delay from config.
     */
    private function getMaxCheckDelay(): int
    {
        return (int) config('zatca.clearance_state.max_check_delay_seconds', 3600);
    }

    /**
     * Parse ZATCA response and determine clearance state.
     *
     * @param array $zatcaResponse The response from ZATCA API
     * @param bool $isSimplified Whether this is a simplified (B2C) invoice
     * @return array{state: string, is_terminal: bool, warnings: array, errors: array}
     */
    public function parseResponse(array $zatcaResponse, bool $isSimplified = false): array
    {
        $clearanceStatus = $zatcaResponse['clearanceStatus'] ?? null;
        $reportingStatus = $zatcaResponse['reportingStatus'] ?? null;
        $validationResults = $zatcaResponse['validationResults'] ?? [];

        // Extract warnings and errors
        $warnings = [];
        $errors = [];

        if (isset($validationResults['warningMessages'])) {
            $warnings = array_map(
                fn($w) => [
                    'code' => $w['code'] ?? 'UNKNOWN',
                    'message' => $w['message'] ?? '',
                    'category' => $w['category'] ?? 'general',
                ],
                $validationResults['warningMessages']
            );
        }

        if (isset($validationResults['errorMessages'])) {
            $errors = array_map(
                fn($e) => [
                    'code' => $e['code'] ?? 'UNKNOWN',
                    'message' => $e['message'] ?? '',
                    'category' => $e['category'] ?? 'general',
                ],
                $validationResults['errorMessages']
            );
        }

        // Determine state based on response
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
     * Determine clearance state from ZATCA status values.
     */
    private function determineState(
        ?string $clearanceStatus,
        ?string $reportingStatus,
        bool $isSimplified,
        array $errors
    ): string {
        // If there are errors, it's rejected
        if (!empty($errors)) {
            return self::STATE_REJECTED;
        }

        // For B2B (standard) invoices
        if (!$isSimplified) {
            if ($clearanceStatus === 'CLEARED') {
                return self::STATE_CLEARED;
            }
            if ($clearanceStatus === 'NOT_CLEARED') {
                return self::STATE_REJECTED;
            }
            if ($clearanceStatus === 'PENDING') {
                return self::STATE_PENDING_CLEARANCE;
            }
        }

        // For B2C (simplified) invoices
        if ($isSimplified) {
            if ($reportingStatus === 'REPORTED') {
                return self::STATE_REPORTED;
            }
            if ($reportingStatus === 'NOT_REPORTED') {
                return self::STATE_REJECTED;
            }
            if ($reportingStatus === 'PENDING') {
                return self::STATE_PENDING_CLEARANCE;
            }
        }

        // If we got a 200 but unclear status, it's conditionally accepted
        if ($clearanceStatus || $reportingStatus) {
            return self::STATE_CONDITIONALLY_ACCEPTED;
        }

        return self::STATE_UNKNOWN;
    }

    /**
     * Update submission clearance state.
     */
    public function updateState(
        string $submissionId,
        string $newState,
        ?array $zatcaResponse = null
    ): void {
        $submission = DB::table('invoice_submissions')
            ->where('id', $submissionId)
            ->first();

        if (!$submission) {
            throw new ZatcaException(
                'Submission not found',
                ErrorCode::VAL_INVALID_INVOICE_ID,
                ['submission_id' => $submissionId]
            );
        }

        $oldState = $submission->clearance_state ?? self::STATE_UNKNOWN;

        $updates = [
            'clearance_state' => $newState,
            'updated_at' => now(),
        ];

        if (in_array($newState, self::TERMINAL_STATES, true)) {
            $updates['clearance_confirmed_at'] = now();
        }

        if ($zatcaResponse) {
            // Merge with existing response data
            $existingResponse = $submission->zatca_response
                ? json_decode($submission->zatca_response, true)
                : [];
            $updates['zatca_response'] = json_encode(array_merge($existingResponse, $zatcaResponse));
        }

        DB::table('invoice_submissions')
            ->where('id', $submissionId)
            ->update($updates);

        Log::info('Clearance state updated', [
            'submission_id' => $submissionId,
            'old_state' => $oldState,
            'new_state' => $newState,
            'is_terminal' => in_array($newState, self::TERMINAL_STATES, true),
        ]);
    }

    /**
     * Record a check attempt and schedule next if needed.
     */
    public function recordCheckAttempt(string $submissionId, bool $stateResolved): array
    {
        $submission = DB::table('invoice_submissions')
            ->where('id', $submissionId)
            ->first();

        if (!$submission) {
            return ['scheduled' => false, 'reason' => 'submission_not_found'];
        }

        $checkCount = ($submission->clearance_check_count ?? 0) + 1;

        $updates = [
            'clearance_check_count' => $checkCount,
            'updated_at' => now(),
        ];

        if ($stateResolved) {
            DB::table('invoice_submissions')
                ->where('id', $submissionId)
                ->update($updates);

            return [
                'scheduled' => false,
                'reason' => 'state_resolved',
                'check_count' => $checkCount,
            ];
        }

        // Check if we've exceeded max attempts
        if ($checkCount >= $this->getMaxCheckAttempts()) {
            $updates['clearance_state'] = self::STATE_TIMEOUT;
            $updates['clearance_confirmed_at'] = now();

            DB::table('invoice_submissions')
                ->where('id', $submissionId)
                ->update($updates);

            Log::warning('Clearance check timeout', [
                'submission_id' => $submissionId,
                'check_count' => $checkCount,
                'max_attempts' => $this->getMaxCheckAttempts(),
            ]);

            return [
                'scheduled' => false,
                'reason' => 'max_attempts_exceeded',
                'check_count' => $checkCount,
            ];
        }

        // Calculate next check delay with exponential backoff
        $delay = $this->calculateNextDelay($checkCount);

        DB::table('invoice_submissions')
            ->where('id', $submissionId)
            ->update($updates);

        return [
            'scheduled' => true,
            'next_check_at' => now()->addSeconds($delay)->toIso8601String(),
            'delay_seconds' => $delay,
            'check_count' => $checkCount,
        ];
    }

    /**
     * Calculate delay for next check attempt with exponential backoff.
     */
    private function calculateNextDelay(int $attemptNumber): int
    {
        // Exponential backoff: 30s, 60s, 120s, 240s, 480s, 960s, 1920s, 3600s...
        $delay = $this->getInitialCheckDelay() * pow(2, $attemptNumber - 1);

        return (int) min($delay, $this->getMaxCheckDelay());
    }

    /**
     * Get submissions that need clearance re-check.
     */
    public function getSubmissionsNeedingCheck(int $limit = 50): array
    {
        return DB::table('invoice_submissions')
            ->whereIn('clearance_state', [
                self::STATE_UNKNOWN,
                self::STATE_PENDING_CLEARANCE,
                self::STATE_CONDITIONALLY_ACCEPTED,
            ])
            ->where('clearance_check_count', '<', $this->getMaxCheckAttempts())
            ->orderBy('submitted_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn($s) => (array) $s)
            ->toArray();
    }

    /**
     * Get summary of clearance states for organization.
     */
    public function getStateSummary(string $organizationId): array
    {
        $counts = DB::table('invoice_submissions')
            ->join('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->where('invoices.organization_id', $organizationId)
            ->selectRaw('clearance_state, count(*) as count')
            ->groupBy('clearance_state')
            ->pluck('count', 'clearance_state')
            ->toArray();

        $pendingCount = ($counts[self::STATE_PENDING_CLEARANCE] ?? 0)
            + ($counts[self::STATE_CONDITIONALLY_ACCEPTED] ?? 0)
            + ($counts[self::STATE_UNKNOWN] ?? 0);

        return [
            'organization_id' => $organizationId,
            'states' => [
                'cleared' => $counts[self::STATE_CLEARED] ?? 0,
                'reported' => $counts[self::STATE_REPORTED] ?? 0,
                'rejected' => $counts[self::STATE_REJECTED] ?? 0,
                'pending' => $pendingCount,
                'timeout' => $counts[self::STATE_TIMEOUT] ?? 0,
            ],
            'needs_attention' => $pendingCount > 0 || ($counts[self::STATE_TIMEOUT] ?? 0) > 0,
        ];
    }

    /**
     * Check if clearance state is terminal (final).
     */
    public function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /**
     * Check if clearance was successful.
     */
    public function isSuccess(string $state): bool
    {
        return $state === self::STATE_CLEARED || $state === self::STATE_REPORTED;
    }
}
