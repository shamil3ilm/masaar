<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\Log;

/**
 * Timestamp Validator Service.
 *
 * Enforces the timestamp policy:
 * 1. System UTC is the issuance time; no timestamp authority is used
 * 2. ERP timestamp is informational only
 *
 * Clock drift tolerance: ±30 seconds between sources.
 *
 * @see docs/COMPLIANCE-POLICIES.md Section 7: Timestamp Authority
 */
class TimestampValidator
{
    /**
     * Maximum allowed clock drift in seconds.
     */
    public const MAX_DRIFT_SECONDS = 30;

    /**
     * Timestamp authority types.
     */
    public const AUTHORITY_LOCAL = 'local';

    public const AUTHORITY_ERP = 'erp';

    /**
     * Validation result codes.
     */
    public const RESULT_VALID = 'valid';

    public const RESULT_DRIFT_WARNING = 'drift_warning';

    public const RESULT_DRIFT_ERROR = 'drift_error';

    public const RESULT_FUTURE_TIMESTAMP = 'future_timestamp';

    public const RESULT_STALE_TIMESTAMP = 'stale_timestamp';

    /**
     * Validate invoice timestamp against multiple sources.
     *
     * @param  \DateTimeInterface|string|null  $invoiceTimestamp  The invoice's claimed issue time
     * @param  \DateTimeInterface|string|null  $erpTimestamp  ERP-provided timestamp (if any)
     * @param  \DateTimeInterface|string|null  $zatcaReceivedAt  ZATCA's received timestamp (from clearance response)
     * @return array{valid: bool, authority: string, drift_seconds: int, warnings: array, errors: array}
     */
    public function validateTimestamps(
        \DateTimeInterface|string|null $invoiceTimestamp,
        \DateTimeInterface|string|null $erpTimestamp = null,
        \DateTimeInterface|string|null $zatcaReceivedAt = null
    ): array {
        // Handle null/empty invoice timestamp
        if ($invoiceTimestamp === null || $invoiceTimestamp === '') {
            return [
                'valid' => false,
                'authority' => self::AUTHORITY_LOCAL,
                'authoritative_time' => null,
                'drift_seconds' => 0,
                'warnings' => [],
                'errors' => ['Invoice timestamp is required'],
                'result_code' => 'missing_timestamp',
            ];
        }

        // Convert string timestamps to DateTimeInterface
        $invoiceTimestamp = $this->parseTimestamp($invoiceTimestamp);
        if ($invoiceTimestamp === null) {
            return [
                'valid' => false,
                'authority' => self::AUTHORITY_LOCAL,
                'authoritative_time' => null,
                'drift_seconds' => 0,
                'warnings' => [],
                'errors' => ['Invalid invoice timestamp format'],
                'result_code' => 'invalid_timestamp_format',
            ];
        }

        $erpTimestamp = $this->parseTimestamp($erpTimestamp);
        $zatcaReceivedAt = $this->parseTimestamp($zatcaReceivedAt);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $warnings = [];
        $errors = [];
        $maxDriftFound = 0;

        // Determine authoritative timestamp
        $authority = self::AUTHORITY_LOCAL;
        $authoritativeTime = $invoiceTimestamp;

        // Check 1: Invoice timestamp should not be in the future
        $futureCheck = $this->checkFutureTimestamp($invoiceTimestamp, $now);
        if ($futureCheck['is_future']) {
            if ($futureCheck['seconds_ahead'] > self::MAX_DRIFT_SECONDS) {
                $errors[] = sprintf(
                    'Invoice timestamp is %d seconds in the future (max allowed: %d)',
                    $futureCheck['seconds_ahead'],
                    self::MAX_DRIFT_SECONDS
                );
            } else {
                $warnings[] = sprintf(
                    'Invoice timestamp is %d seconds ahead of server time (within tolerance)',
                    $futureCheck['seconds_ahead']
                );
            }
            $maxDriftFound = max($maxDriftFound, $futureCheck['seconds_ahead']);
        }

        // Check 2: ERP timestamp drift from system time
        if ($erpTimestamp !== null) {
            $erpDrift = $this->calculateDrift($invoiceTimestamp, $erpTimestamp);

            if (abs($erpDrift) > self::MAX_DRIFT_SECONDS) {
                $warnings[] = sprintf(
                    'ERP timestamp differs from invoice timestamp by %d seconds (exceeds %ds tolerance). ERP time is informational only.',
                    $erpDrift,
                    self::MAX_DRIFT_SECONDS
                );
                $maxDriftFound = max($maxDriftFound, abs($erpDrift));
            }
        }

        // Check 3: ZATCA received timestamp (for dispute resolution)
        if ($zatcaReceivedAt !== null) {
            $zatcaDrift = $this->calculateDrift($authoritativeTime, $zatcaReceivedAt);

            if (abs($zatcaDrift) > self::MAX_DRIFT_SECONDS) {
                $warnings[] = sprintf(
                    'ZATCA received timestamp differs from authoritative timestamp by %d seconds',
                    $zatcaDrift
                );
                $maxDriftFound = max($maxDriftFound, abs($zatcaDrift));
            }
        }

        // Check 4: Staleness (invoice too old)
        $ageSeconds = $now->getTimestamp() - $invoiceTimestamp->getTimestamp();
        if ($ageSeconds > 86400) { // More than 24 hours old
            $warnings[] = sprintf(
                'Invoice timestamp is %s old. Consider if this is intentional.',
                $this->formatDuration($ageSeconds)
            );
        }

        $isValid = empty($errors);

        if (! $isValid || ! empty($warnings)) {
            Log::warning('Timestamp validation completed', [
                'authority' => $authority,
                'drift_seconds' => $maxDriftFound,
                'warnings' => $warnings,
                'errors' => $errors,
                'invoice_timestamp' => $invoiceTimestamp->format('c'),
                'erp_timestamp' => $erpTimestamp?->format('c'),
            ]);
        }

        return [
            'valid' => $isValid,
            'authority' => $authority,
            'authoritative_time' => $authoritativeTime->format('c'),
            'drift_seconds' => $maxDriftFound,
            'warnings' => $warnings,
            'errors' => $errors,
            'result_code' => $this->determineResultCode($isValid, $maxDriftFound, $warnings),
        ];
    }

    /**
     * Parse a timestamp from various formats.
     */
    private function parseTimestamp(\DateTimeInterface|string|null $timestamp): ?\DateTimeInterface
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp;
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Exception $e) {
            Log::debug('Failed to parse timestamp', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Calculate drift between two timestamps in seconds.
     *
     * @return int Positive means second is ahead, negative means second is behind
     */
    private function calculateDrift(\DateTimeInterface $first, \DateTimeInterface $second): int
    {
        return $second->getTimestamp() - $first->getTimestamp();
    }

    /**
     * Check if timestamp is in the future.
     */
    private function checkFutureTimestamp(\DateTimeInterface $timestamp, \DateTimeInterface $now): array
    {
        $diff = $timestamp->getTimestamp() - $now->getTimestamp();

        return [
            'is_future' => $diff > 0,
            'seconds_ahead' => max(0, $diff),
        ];
    }

    /**
     * Determine result code based on validation outcome.
     */
    private function determineResultCode(bool $isValid, int $maxDrift, array $warnings): string
    {
        if (! $isValid) {
            return self::RESULT_DRIFT_ERROR;
        }

        if ($maxDrift > 0) {
            return self::RESULT_DRIFT_WARNING;
        }

        if (! empty($warnings)) {
            return self::RESULT_DRIFT_WARNING;
        }

        return self::RESULT_VALID;
    }

    /**
     * Format duration in human-readable form.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);

            return "{$minutes} minute".($minutes > 1 ? 's' : '');
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);

            return "{$hours} hour".($hours > 1 ? 's' : '');
        }

        $days = floor($seconds / 86400);

        return "{$days} day".($days > 1 ? 's' : '');
    }
}
