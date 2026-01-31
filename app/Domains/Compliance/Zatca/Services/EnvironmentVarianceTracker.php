<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
class EnvironmentVarianceTracker
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
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Log a production failure and check for sandbox variance.
     *
     * @param string $organizationId
     * @param string|null $invoiceId
     * @param string $payloadHash SHA-256 of the request payload
     * @param array $productionResult The production API response
     * @return array|null Variance record if detected, null otherwise
     */
    public function checkAndLogVariance(
        string $organizationId,
        ?string $invoiceId,
        string $payloadHash,
        array $productionResult
    ): ?array {
        // Check if we have a cached sandbox result for this payload
        $sandboxResult = Cache::get(self::SANDBOX_CACHE_PREFIX . $payloadHash);

        if (!$sandboxResult) {
            // No sandbox result to compare
            return null;
        }

        // Determine if there's a variance
        $variance = $this->detectVariance($sandboxResult, $productionResult);

        if (!$variance) {
            return null;
        }

        // Log the variance
        $varianceId = \Illuminate\Support\Str::uuid()->toString();

        DB::table('environment_variance_log')->insert([
            'id' => $varianceId,
            'organization_id' => $organizationId,
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
        ]);

        Log::warning('Environment variance detected', [
            'variance_id' => $varianceId,
            'variance_type' => $variance['type'],
            'organization_id' => $organizationId,
            'invoice_id' => $invoiceId,
            'payload_hash' => $payloadHash,
        ]);

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
            self::SANDBOX_CACHE_PREFIX . $payloadHash,
            $result,
            self::CACHE_TTL
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
        if ($sandboxSuccess && !$productionSuccess) {
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
        if (!$sandboxSuccess && !$productionSuccess) {
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
        return DB::table('environment_variance_log')
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
        $query = DB::table('environment_variance_log')
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at');

        if ($type) {
            $query->where('variance_type', $type);
        }

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Mark variance as reported to ZATCA.
     */
    public function markReportedToZatca(string $varianceId, string $ticketId): void
    {
        DB::table('environment_variance_log')
            ->where('id', $varianceId)
            ->update([
                'reported_to_zatca' => true,
                'zatca_ticket_id' => $ticketId,
                'resolution_status' => self::STATUS_REPORTED,
                'updated_at' => now(),
            ]);
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
            $update['notes'] = DB::raw("CONCAT(COALESCE(notes, ''), '\n\nResolution: " . addslashes($notes) . "')");
        }

        DB::table('environment_variance_log')
            ->where('id', $varianceId)
            ->update($update);
    }

    /**
     * Generate customer-friendly variance report.
     */
    public function generateVarianceReport(string $varianceId): array
    {
        $variance = $this->getVariance($varianceId);

        if (!$variance) {
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
     * Get variance statistics.
     */
    public function getStatistics(?string $organizationId = null): array
    {
        $query = DB::table('environment_variance_log');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $total = $query->count();

        $byType = DB::table('environment_variance_log')
            ->when($organizationId, fn($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('variance_type, COUNT(*) as count')
            ->groupBy('variance_type')
            ->pluck('count', 'variance_type')
            ->toArray();

        $byStatus = DB::table('environment_variance_log')
            ->when($organizationId, fn($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('resolution_status, COUNT(*) as count')
            ->groupBy('resolution_status')
            ->pluck('count', 'resolution_status')
            ->toArray();

        $reportedToZatca = DB::table('environment_variance_log')
            ->when($organizationId, fn($q) => $q->where('organization_id', $organizationId))
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
