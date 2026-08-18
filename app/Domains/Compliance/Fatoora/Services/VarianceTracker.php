<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Environment Variance Tracker.
 *
 * Tracks behavioral differences between ZATCA sandbox and production environments.
 *
 * POLICY: All behavioral differences between environments must be tracked
 * to support customer disputes ("But it worked in sandbox!") and debugging.
 *
 * Variance Types:
 * - sandbox_only_pass: Accepted in sandbox, rejected in production
 * - production_only_fail: Same payload, different error in production
 * - validation_difference: Different error codes for same issue
 * - timing_difference: Timeout/latency differences
 */
class VarianceTracker
{
    /**
     * Variance types.
     */
    public const TYPE_SANDBOX_ONLY_PASS = 'sandbox_only_pass';

    public const TYPE_PRODUCTION_ONLY_FAIL = 'production_only_fail';

    public const TYPE_VALIDATION_DIFFERENCE = 'validation_difference';

    public const TYPE_TIMING_DIFFERENCE = 'timing_difference';

    /**
     * Resolution statuses.
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_WONT_FIX = 'wont_fix';

    public const STATUS_REPORTED = 'reported_to_zatca';

    /**
     * Cache key for sandbox results.
     */
    private const SANDBOX_CACHE_PREFIX = 'zatca:sandbox_result:';

    /**
     * Get cache TTL in seconds from config.
     */
    private function getCacheTtl(): int
    {
        return (int) config('fatoora.variance_tracking.cache_ttl_hours', 24) * 3600;
    }

    /**
     * Log a production failure and check for sandbox variance.
     *
     * Performance-optimized for hot path:
     * - Cache check is synchronous but fast (Redis)
     * - Database insert uses deferred/async option for high-volume scenarios
     *
     * @param  string  $payloadHash  SHA-256 of the request payload
     * @param  array  $productionResult  The production API response
     * @param  bool  $async  If true, queue the database insert (for high-volume scenarios)
     * @return array|null Variance record if detected, null otherwise
     */
    public function checkAndLogVariance(
        string $organizationId,
        ?string $invoiceId,
        string $payloadHash,
        array $productionResult,
        bool $async = false
    ): ?array {
        // Fast path: Check cache with short timeout
        try {
            $sandboxResult = Cache::get(self::SANDBOX_CACHE_PREFIX.$payloadHash);
        } catch (\Exception $e) {
            // Cache failure should not block production submissions
            Log::debug('Cache check failed during variance tracking', [
                'payload_hash' => $payloadHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $sandboxResult) {
            // No sandbox result to compare - common case, return quickly
            return null;
        }

        // Determine if there's a variance
        $variance = $this->detectVariance($sandboxResult, $productionResult);

        if (! $variance) {
            return null;
        }

        // Log the variance
        $varianceId = Str::uuid()->toString();

        $record = [
            'id' => $varianceId,
            'org_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'variance_type' => $variance['type'],
            'rule_code' => $variance['rule_code'] ?? null,
            'sandbox_result' => json_encode($sandboxResult),
            'production_result' => json_encode($productionResult),
            'payload_hash' => $payloadHash,
            'notes' => $variance['notes'] ?? null,
            'resolution_status' => self::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($async) {
            // Queue for async insert to avoid blocking production path
            dispatch(function () use ($record, $varianceId, $variance, $organizationId) {
                try {
                    DB::table('variance_logs')->insert($record);
                    Log::warning('Environment variance detected (async)', [
                        'variance_id' => $varianceId,
                        'variance_type' => $variance['type'],
                        'org_id' => $organizationId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to insert variance record async', [
                        'variance_id' => $varianceId,
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        } else {
            DB::table('variance_logs')->insert($record);

            Log::warning('Environment variance detected', [
                'variance_id' => $varianceId,
                'variance_type' => $variance['type'],
                'org_id' => $organizationId,
                'invoice_id' => $invoiceId,
                'payload_hash' => $payloadHash,
            ]);
        }

        return [
            'variance_id' => $varianceId,
            'variance_type' => $variance['type'],
            'sandbox_passed' => $this->wasSuccessful($sandboxResult),
            'production_passed' => $this->wasSuccessful($productionResult),
        ];
    }

    /**
     * Store sandbox result for later comparison.
     */
    public function storeSandboxResult(string $payloadHash, array $result): void
    {
        Cache::put(
            self::SANDBOX_CACHE_PREFIX.$payloadHash,
            $result,
            $this->getCacheTtl()
        );
    }

    /**
     * Detect variance between sandbox and production results.
     */
    private function detectVariance(array $sandboxResult, array $productionResult): ?array
    {
        $sandboxSuccess = $this->wasSuccessful($sandboxResult);
        $productionSuccess = $this->wasSuccessful($productionResult);

        // Case 1: Sandbox passed, production failed
        if ($sandboxSuccess && ! $productionSuccess) {
            return [
                'type' => self::TYPE_SANDBOX_ONLY_PASS,
                'rule_code' => $productionResult['error_code'] ?? $productionResult['validationResults']['errorMessages'][0]['code'] ?? null,
                'notes' => sprintf(
                    'Sandbox accepted this payload on %s, but production rejected it with: %s',
                    $sandboxResult['timestamp'] ?? 'unknown',
                    $productionResult['error_message'] ?? json_encode($productionResult['validationResults'] ?? [])
                ),
            ];
        }

        // Case 2: Both failed but with different errors
        if (! $sandboxSuccess && ! $productionSuccess) {
            $sandboxError = $sandboxResult['error_code'] ?? 'unknown';
            $productionError = $productionResult['error_code'] ?? 'unknown';

            if ($sandboxError !== $productionError) {
                return [
                    'type' => self::TYPE_VALIDATION_DIFFERENCE,
                    'rule_code' => $productionError,
                    'notes' => sprintf(
                        'Different error codes: Sandbox=%s, Production=%s',
                        $sandboxError,
                        $productionError
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * Check if a result represents success.
     */
    private function wasSuccessful(array $result): bool
    {
        // Check various success indicators
        if (isset($result['clearanceStatus']) && $result['clearanceStatus'] === 'CLEARED') {
            return true;
        }
        if (isset($result['reportingStatus']) && $result['reportingStatus'] === 'REPORTED') {
            return true;
        }
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }
        if (isset($result['status_code']) && $result['status_code'] >= 200 && $result['status_code'] < 300) {
            return true;
        }

        return false;
    }

    /**
     * Get variance by ID.
     */
    public function getVariance(string $varianceId): ?object
    {
        return DB::table('variance_logs')
            ->where('id', $varianceId)
            ->first();
    }

    /**
     * Get variances for an organization.
     */
    public function getOrganizationVariances(
        string $organizationId,
        ?string $type = null,
        int $limit = 50
    ): array {
        $query = DB::table('variance_logs')
            ->where('org_id', $organizationId)
            ->orderByDesc('created_at');

        if ($type) {
            $query->where('variance_type', $type);
        }

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Mark variance as reported to ZATCA.
     *
     * Implements retry logic to handle transient database failures.
     *
     * @param  int  $maxRetries  Maximum retry attempts (default: 3)
     *
     * @throws \RuntimeException If all retries fail
     */
    public function markReportedToZatca(string $varianceId, string $ticketId, int $maxRetries = 3): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $updated = DB::table('variance_logs')
                    ->where('id', $varianceId)
                    ->update([
                        'reported_to_zatca' => true,
                        'zatca_ticket_id' => $ticketId,
                        'resolution_status' => self::STATUS_REPORTED,
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    Log::warning('Variance not found for ZATCA reporting', [
                        'variance_id' => $varianceId,
                        'ticket_id' => $ticketId,
                    ]);
                } else {
                    Log::info('Variance marked as reported to ZATCA', [
                        'variance_id' => $varianceId,
                        'ticket_id' => $ticketId,
                        'attempt' => $attempt,
                    ]);
                }

                return; // Success
            } catch (\Exception $e) {
                $lastException = $e;

                Log::warning('Failed to mark variance as reported (attempt {attempt})', [
                    'variance_id' => $varianceId,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    // Exponential backoff: 100ms, 200ms, 400ms...
                    usleep(100000 * (2 ** ($attempt - 1)));
                }
            }
        }

        // All retries exhausted
        Log::error('All retries exhausted for marking variance as reported', [
            'variance_id' => $varianceId,
            'ticket_id' => $ticketId,
            'error' => $lastException?->getMessage(),
        ]);

        throw new \RuntimeException(
            "Failed to mark variance {$varianceId} as reported after {$maxRetries} attempts: ".
            $lastException?->getMessage()
        );
    }

    /**
     * Resolve a variance.
     */
    public function resolveVariance(string $varianceId, string $status, ?string $notes = null): void
    {
        $update = [
            'resolution_status' => $status,
            'updated_at' => now(),
        ];

        if ($notes) {
            // Fetch and append in PHP to avoid any SQL injection risk from string interpolation
            $existing = DB::table('variance_logs')
                ->where('id', $varianceId)
                ->value('notes');
            $update['notes'] = ($existing ?? '')."\n\nResolution: ".$notes;
        }

        DB::table('variance_logs')
            ->where('id', $varianceId)
            ->update($update);
    }

    /**
     * Generate customer-friendly variance report.
     */
    public function generateVarianceReport(string $varianceId): array
    {
        $variance = $this->getVariance($varianceId);

        if (! $variance) {
            return ['error' => 'Variance not found'];
        }

        $sandboxResult = json_decode($variance->sandbox_result, true);
        $productionResult = json_decode($variance->production_result, true);

        return [
            'variance_id' => $variance->id,
            'type' => $variance->variance_type,
            'type_description' => $this->getTypeDescription($variance->variance_type),
            'invoice_id' => $variance->invoice_id,
            'detected_at' => $variance->created_at,
            'status' => $variance->resolution_status,
            'sandbox' => [
                'passed' => $this->wasSuccessful($sandboxResult),
                'timestamp' => $sandboxResult['timestamp'] ?? null,
            ],
            'production' => [
                'passed' => $this->wasSuccessful($productionResult),
                'error_code' => $variance->rule_code,
                'error_message' => $productionResult['error_message'] ?? null,
            ],
            'zatca_reported' => $variance->reported_to_zatca,
            'zatca_ticket' => $variance->zatca_ticket_id,
            'customer_message' => $this->generateCustomerMessage($variance),
        ];
    }

    /**
     * Get human-readable type description.
     */
    private function getTypeDescription(string $type): string
    {
        return match ($type) {
            self::TYPE_SANDBOX_ONLY_PASS => 'Invoice passed in sandbox but failed in production',
            self::TYPE_PRODUCTION_ONLY_FAIL => 'Invoice failed only in production environment',
            self::TYPE_VALIDATION_DIFFERENCE => 'Different validation errors between environments',
            self::TYPE_TIMING_DIFFERENCE => 'Timing/latency difference between environments',
            default => 'Unknown variance type',
        };
    }

    /**
     * Generate customer-friendly message.
     */
    private function generateCustomerMessage(object $variance): string
    {
        $message = sprintf(
            "Your invoice was rejected in production with error code %s.\n\n",
            $variance->rule_code ?? 'UNKNOWN'
        );

        if ($variance->variance_type === self::TYPE_SANDBOX_ONLY_PASS) {
            $message .= "This same payload was accepted in sandbox.\n\n";
        }

        $message .= sprintf(
            "Variance ID: %s\nThis has been logged for review.\n\n",
            $variance->id
        );

        if ($variance->reported_to_zatca) {
            $message .= sprintf(
                "This issue has been reported to ZATCA (Ticket: %s).\n",
                $variance->zatca_ticket_id ?? 'pending'
            );
        } else {
            $message .= "Please contact support with this variance ID for further assistance.\n";
        }

        return $message;
    }

    /**
     * Check for new/unknown ZATCA rule codes and flag for review.
     *
     * ZATCA occasionally changes business-rule enforcement silently.
     * This method detects rule codes we haven't seen before and flags them.
     *
     * @param  array  $result  ZATCA API response
     * @return array|null Details about new rule codes if detected
     */
    public function checkForNewRuleCodes(array $result): ?array
    {
        $ruleCodes = $this->extractRuleCodes($result);

        if (empty($ruleCodes)) {
            return null;
        }

        // Get known rule codes from cache
        $knownCodes = Cache::get('zatca:known_rule_codes', []);

        // Find new codes
        $newCodes = array_diff($ruleCodes, $knownCodes);

        if (empty($newCodes)) {
            return null;
        }

        // Log and store new codes
        foreach ($newCodes as $code) {
            Log::warning('New ZATCA rule code detected - flagged for review', [
                'rule_code' => $code,
                'first_seen_at' => now()->toIso8601String(),
                'needs_review' => true,
            ]);

            // Store in database for tracking
            DB::table('zatca_rule_codes')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'code' => $code,
                'first_seen_at' => now(),
                'needs_review' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update known codes cache (merge with new codes)
        $allKnownCodes = array_unique(array_merge($knownCodes, $ruleCodes));
        Cache::put('zatca:known_rule_codes', $allKnownCodes, now()->addDays(30));

        return [
            'new_codes' => array_values($newCodes),
            'needs_review' => true,
            'message' => 'New ZATCA rule codes detected. Please review for potential business rule changes.',
        ];
    }

    /**
     * Extract all rule codes from a ZATCA response.
     */
    private function extractRuleCodes(array $result): array
    {
        $codes = [];

        // Extract from error_code
        if (! empty($result['error_code'])) {
            $codes[] = $result['error_code'];
        }

        // Extract from validationResults
        if (! empty($result['validationResults']['errorMessages'])) {
            foreach ($result['validationResults']['errorMessages'] as $error) {
                if (! empty($error['code'])) {
                    $codes[] = $error['code'];
                }
            }
        }

        if (! empty($result['validationResults']['warningMessages'])) {
            foreach ($result['validationResults']['warningMessages'] as $warning) {
                if (! empty($warning['code'])) {
                    $codes[] = $warning['code'];
                }
            }
        }

        // Extract BR-KSA codes
        if (! empty($result['errors'])) {
            foreach ((array) $result['errors'] as $error) {
                if (is_string($error) && preg_match('/BR-KSA-\d+/', $error, $matches)) {
                    $codes[] = $matches[0];
                } elseif (is_array($error) && ! empty($error['code'])) {
                    $codes[] = $error['code'];
                }
            }
        }

        return array_unique($codes);
    }

    /**
     * Get rule codes that need review.
     */
    public function getRuleCodesNeedingReview(): array
    {
        return DB::table('zatca_rule_codes')
            ->where('needs_review', true)
            ->orderByDesc('first_seen_at')
            ->get()
            ->toArray();
    }

    /**
     * Mark a rule code as reviewed.
     */
    public function markRuleCodeReviewed(string $code, ?string $notes = null): void
    {
        DB::table('zatca_rule_codes')
            ->where('code', $code)
            ->update([
                'needs_review' => false,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'updated_at' => now(),
            ]);

        Log::info('ZATCA rule code marked as reviewed', [
            'code' => $code,
            'notes' => $notes,
        ]);
    }

    /**
     * Get variance statistics.
     */
    public function getStatistics(?string $organizationId = null): array
    {
        $query = DB::table('variance_logs');

        if ($organizationId) {
            $query->where('org_id', $organizationId);
        }

        $total = $query->count();

        $byType = DB::table('variance_logs')
            ->when($organizationId, fn ($q) => $q->where('org_id', $organizationId))
            ->selectRaw('variance_type, COUNT(*) as count')
            ->groupBy('variance_type')
            ->pluck('count', 'variance_type')
            ->toArray();

        $byStatus = DB::table('variance_logs')
            ->when($organizationId, fn ($q) => $q->where('org_id', $organizationId))
            ->selectRaw('resolution_status, COUNT(*) as count')
            ->groupBy('resolution_status')
            ->pluck('count', 'resolution_status')
            ->toArray();

        $reportedToZatca = DB::table('variance_logs')
            ->when($organizationId, fn ($q) => $q->where('org_id', $organizationId))
            ->where('reported_to_zatca', true)
            ->count();

        return [
            'total' => $total,
            'by_type' => $byType,
            'by_status' => $byStatus,
            'reported_to_zatca' => $reportedToZatca,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
