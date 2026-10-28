<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitRetirement;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's tills - its EGS units - over the API: listing them,
 * adding one to a branch, and onboarding each with its own OTP from the
 * Fatoora portal (UnitOnboarding). An invoice names its till by the id this
 * lists, or by its branch and till number.
 */
class UnitController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Tills $tills,
        private readonly BranchService $branches,
        private readonly UnitOnboarding $onboarding,
        private readonly UnitRetirement $retirement,
    ) {}

    /**
     * GET /api/organizations/units
     */
    public function index(): JsonResponse
    {
        $organizationId = (string) $this->tenant->getOrganizationId();

        return ApiResponse::success([
            'units' => $this->tills->all($organizationId)->map(fn (EgsUnit $unit): array => $this->present($unit))->values()->all(),
        ]);
    }

    /**
     * POST /api/organizations/branches/{branch}/units
     */
    public function store(Request $request, string $branchId): JsonResponse
    {
        $organizationId = (string) $this->tenant->getOrganizationId();
        $branch = $this->branches->find($organizationId, $branchId);

        if ($branch === null) {
            return ApiResponse::notFound('Branch not found');
        }

        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $unit = $this->tills->add($branch, $data['name'] ?? null);

        return ApiResponse::created(['unit' => $this->present($unit->fresh())], 'Till added. Onboard it with its own OTP from the Fatoora portal.');
    }

    /**
     * POST /api/organizations/units/{unit}/onboarding/ccsid
     *
     * Step 1, with an OTP from the Fatoora portal for this till, valid for
     * an hour.
     */
    public function ccsid(Request $request, string $unitId): JsonResponse
    {
        $request->validate(['otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/']]);

        return $this->step($unitId, function (EgsUnit $unit) use ($request): JsonResponse {
            $result = $this->onboarding->requestCcsid($this->tenant->getOrganization(), $unit, (string) $request->input('otp'), $request->input('renewal_override_reason'));

            return ApiResponse::success([
                'unit_id' => $unit->id,
                'request_id' => $result['request_id'],
                'next_step' => 'POST /api/organizations/units/'.$unit->id.'/onboarding/compliance-check',
            ], 'Compliance CSID obtained. Proceed with compliance checks.');
        });
    }

    /**
     * POST /api/organizations/units/{unit}/onboarding/compliance-check
     */
    public function complianceCheck(string $unitId): JsonResponse
    {
        return $this->step($unitId, function (EgsUnit $unit): JsonResponse {
            $result = $this->onboarding->runComplianceCheck($this->tenant->getOrganization(), $unit);

            return ApiResponse::success([
                'unit_id' => $unit->id,
                'passed' => $result['passed'],
                'results' => $result['results'],
                'next_step' => $result['passed'] ? 'POST /api/organizations/units/'.$unit->id.'/onboarding/pcsid' : 'Fix errors and retry compliance check',
            ], $result['passed'] ? 'Compliance checks passed. Proceed to request PCSID.' : 'Compliance checks failed. Fix errors and retry.');
        });
    }

    /**
     * POST /api/organizations/units/{unit}/onboarding/pcsid
     */
    public function pcsid(Request $request, string $unitId): JsonResponse
    {
        return $this->step($unitId, function (EgsUnit $unit) use ($request): JsonResponse {
            $this->onboarding->requestPcsid($this->tenant->getOrganization(), $unit, $request->input('renewal_override_reason'));

            return ApiResponse::success(['unit' => $this->present($unit->fresh())], 'Production CSID obtained. The till is ready for invoicing.');
        });
    }

    /**
     * POST /api/organizations/units/{unit}/retire
     *
     * Retired at once when nothing it signed is left to file; otherwise it
     * issues nothing more and is retiring, and asking again once its
     * documents are filed retires it.
     */
    public function retire(Request $request, string $unitId): JsonResponse
    {
        return $this->step($unitId, function (EgsUnit $unit) use ($request): JsonResponse {
            $unit = $this->retirement->retire($this->tenant->getOrganization(), $unit, $request->user()?->getAuthIdentifier());

            return ApiResponse::success([
                'unit' => $this->present($unit),
                'unfiled' => $this->retirement->waiting($unit)->pluck('invoice_number')->all(),
            ], $unit->status === EgsUnit::RETIRED
                ? 'Till retired. It never issues again.'
                : 'Till retiring: it issues nothing more, and is retired once what it signed is filed.');
        });
    }

    /**
     * @param  callable(EgsUnit): JsonResponse  $step
     */
    private function step(string $unitId, callable $step): JsonResponse
    {
        $unit = $this->tills->find((string) $this->tenant->getOrganizationId(), $unitId);

        if ($unit === null) {
            return ApiResponse::notFound('Till not found');
        }

        try {
            return $step($unit);
        } catch (CertificateException $e) {
            return $e->apiResponse();
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EgsUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'branch_id' => $unit->branch_id,
            'name' => $unit->label(),
            'till_number' => $unit->till_number,
            'head_office' => $unit->isHeadOffice(),
            'egs_serial' => $unit->egs_serial,
            'host' => $unit->host,
            'status' => $unit->status,
            'onboarding_status' => $unit->onboarding_status,
            'cert_expires_at' => $unit->cert_expires_at?->toIso8601String(),
            'onboarding' => $unit->isHeadOffice() ? null : $this->onboarding->status($unit)['steps'],
        ];
    }
}
