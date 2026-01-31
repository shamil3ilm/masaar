<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * License Usage Controller (Self-Service).
 *
 * Allows licensees to view their own usage and quota information.
 */
class LicenseUsageController extends Controller
{
    public function __construct(
        private readonly UsageMeteringService $meteringService,
    ) {}

    /**
     * Get current license info.
     *
     * GET /api/license
     */
    public function info(Request $request): JsonResponse
    {
        $license = $this->getLicenseFromRequest($request);

        return response()->json([
            'success' => true,
            'data' => [
                'company_name' => $license->company_name,
                'tier' => $license->tier->value,
                'status' => $license->status->value,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'features' => $license->features,
                'limits' => [
                    'max_invoices_per_month' => $license->max_invoices_per_month,
                    'max_api_calls_per_day' => $license->max_api_calls_per_day,
                    'max_api_calls_per_minute' => $license->max_api_calls_per_minute,
                    'max_organizations' => $license->max_organizations,
                ],
            ],
        ]);
    }

    /**
     * Get usage summary for current license.
     *
     * GET /api/license/usage
     */
    public function usage(Request $request): JsonResponse
    {
        $license = $this->getLicenseFromRequest($request);
        $month = $request->get('month', now()->format('Y-m'));

        $usage = $this->meteringService->getUsageSummary($license, $month);

        return response()->json([
            'success' => true,
            'data' => $usage,
        ]);
    }

    /**
     * Get remaining quotas.
     *
     * GET /api/license/quotas
     */
    public function quotas(Request $request): JsonResponse
    {
        $license = $this->getLicenseFromRequest($request);
        $usage = $this->meteringService->getUsageSummary($license);

        $invoicesRemaining = $license->max_invoices_per_month === -1
            ? -1
            : max(0, $license->max_invoices_per_month - $usage['totals']['invoices_submitted']);

        return response()->json([
            'success' => true,
            'data' => [
                'invoices' => [
                    'used' => $usage['totals']['invoices_submitted'],
                    'limit' => $license->max_invoices_per_month,
                    'remaining' => $invoicesRemaining,
                    'percent_used' => $usage['utilization']['invoices_percent'],
                    'is_unlimited' => $license->max_invoices_per_month === -1,
                ],
                'api_calls' => [
                    'daily_limit' => $license->max_api_calls_per_day,
                    'minute_limit' => $license->max_api_calls_per_minute,
                    'today_used' => $usage['totals']['api_calls'],
                ],
                'organizations' => [
                    'limit' => $license->max_organizations,
                    'is_unlimited' => $license->max_organizations === -1,
                ],
                'success_rate' => $usage['utilization']['success_rate'],
            ],
        ]);
    }

    /**
     * Check if a feature is available.
     *
     * GET /api/license/features/{feature}
     */
    public function checkFeature(Request $request, string $feature): JsonResponse
    {
        $license = $this->getLicenseFromRequest($request);
        $hasFeature = $license->hasFeature($feature);

        return response()->json([
            'success' => true,
            'data' => [
                'feature' => $feature,
                'available' => $hasFeature,
                'tier' => $license->tier->value,
                'all_features' => $hasFeature ? null : $license->features,
            ],
        ]);
    }

    /**
     * Get license health status.
     *
     * GET /api/license/health
     */
    public function health(Request $request): JsonResponse
    {
        $license = $this->getLicenseFromRequest($request);
        $usage = $this->meteringService->getUsageSummary($license);

        $issues = [];

        // Check expiration
        if ($license->expires_at) {
            $daysUntilExpiry = now()->diffInDays($license->expires_at, false);
            if ($daysUntilExpiry <= 0) {
                $issues[] = [
                    'type' => 'critical',
                    'code' => 'LICENSE_EXPIRED',
                    'message' => 'License has expired',
                ];
            } elseif ($daysUntilExpiry <= 7) {
                $issues[] = [
                    'type' => 'warning',
                    'code' => 'LICENSE_EXPIRING_SOON',
                    'message' => "License expires in {$daysUntilExpiry} days",
                ];
            }
        }

        // Check quota usage
        $quotaPercent = $usage['utilization']['invoices_percent'];
        if ($quotaPercent >= 100) {
            $issues[] = [
                'type' => 'critical',
                'code' => 'QUOTA_EXCEEDED',
                'message' => 'Monthly invoice quota exceeded',
            ];
        } elseif ($quotaPercent >= 90) {
            $issues[] = [
                'type' => 'warning',
                'code' => 'QUOTA_ALMOST_EXCEEDED',
                'message' => "Invoice quota at {$quotaPercent}%",
            ];
        }

        // Check success rate
        $successRate = $usage['utilization']['success_rate'];
        if ($successRate < 95 && $usage['totals']['invoices_submitted'] > 10) {
            $issues[] = [
                'type' => 'warning',
                'code' => 'LOW_SUCCESS_RATE',
                'message' => "Invoice success rate is {$successRate}%",
            ];
        }

        $status = empty($issues) ? 'healthy' : (
            collect($issues)->contains('type', 'critical') ? 'critical' : 'warning'
        );

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $status,
                'license_status' => $license->status->value,
                'issues' => $issues,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get license from request attributes.
     */
    private function getLicenseFromRequest(Request $request): License
    {
        $license = $request->attributes->get('license');

        if (!$license instanceof License) {
            throw new \RuntimeException('License not found in request');
        }

        return $license;
    }
}
