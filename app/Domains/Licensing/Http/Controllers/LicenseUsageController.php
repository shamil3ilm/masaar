<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Http\Responses\ApiResponse;
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

        return ApiResponse::success([
            // The column is organization_name; company_name is not one, so
            // this field was null in every response.
            'organization_name' => $license->organization_name,
            'tier' => $license->tier->value,
            'status' => $license->status->value,
            'expires_at' => $license->expires_at?->toIso8601String(),
            'features' => $license->features,
            'limits' => [
                'invoices_per_month' => $license->invoices_per_month,
                'calls_per_day' => $license->calls_per_day,
                'calls_per_min' => $license->calls_per_min,
                'max_organizations' => $license->max_organizations,
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

        // Unvalidated, month[]=x reached a string parameter under strict
        // types and answered 500.
        $month = $request->validate([
            'month' => ['sometimes', 'string', 'date_format:Y-m'],
        ])['month'] ?? now()->format('Y-m');

        $usage = $this->meteringService->getUsageSummary($license, $month);

        return ApiResponse::success($usage);
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

        // -1 is what this endpoint has always said for unlimited, so it still
        // does - the column holds License::UNLIMITED, which no client knows.
        $unlimitedInvoices = $license->isUnlimited('invoices_per_month');
        $unlimitedOrganizations = $license->isUnlimited('max_organizations');

        $invoicesRemaining = $unlimitedInvoices
            ? -1
            : max(0, $license->invoices_per_month - $usage['totals']['invoices_submitted']);

        return ApiResponse::success([
            'invoices' => [
                'used' => $usage['totals']['invoices_submitted'],
                'limit' => $unlimitedInvoices ? -1 : $license->invoices_per_month,
                'remaining' => $invoicesRemaining,
                'percent_used' => $usage['utilization']['invoices_percent'],
                'is_unlimited' => $unlimitedInvoices,
            ],
            'api_calls' => [
                'daily_limit' => $license->isUnlimited('calls_per_day') ? -1 : $license->calls_per_day,
                'minute_limit' => $license->calls_per_min,
                'today_used' => $usage['totals']['api_calls'],
            ],
            'organizations' => [
                'limit' => $unlimitedOrganizations ? -1 : $license->max_organizations,
                'is_unlimited' => $unlimitedOrganizations,
            ],
            'success_rate' => $usage['utilization']['success_rate'],
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

        return ApiResponse::success([
            'feature' => $feature,
            'available' => $hasFeature,
            'tier' => $license->tier->value,
            'all_features' => $hasFeature ? null : $license->features,
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

        return ApiResponse::success([
            'status' => $status,
            'license_status' => $license->status->value,
            'issues' => $issues,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get license from request attributes.
     */
    private function getLicenseFromRequest(Request $request): License
    {
        $license = $request->attributes->get('license');

        if (! $license instanceof License) {
            throw new \RuntimeException('License not found in request');
        }

        return $license;
    }
}
