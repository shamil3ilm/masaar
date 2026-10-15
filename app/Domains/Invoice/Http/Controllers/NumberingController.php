<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\NumberingSettings;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How the organization numbers the documents it issues.
 *
 * An invoice sent without a number is numbered when it is issued, from the
 * sequence for its document type - the branch's own if it has one, else the
 * organization's. Reading is open to members; changing it is for admins.
 */
class NumberingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly NumberingSettings $settings,
        private readonly BranchService $branches,
    ) {}

    /**
     * GET /api/settings/numbering
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = (string) $this->tenant->getOrganizationId();
        $branchId = $this->branch($request, $organizationId);

        return ApiResponse::success([
            'sequences' => $this->settings->sequences($organizationId, $branchId),
        ]);
    }

    /**
     * PUT /api/settings/numbering/{documentType}
     */
    public function update(Request $request, string $documentType): JsonResponse
    {
        $organizationId = (string) $this->tenant->getOrganizationId();

        $request->merge(['document_type' => $documentType]);
        $request->validate(['document_type' => ['required', Rule::in(NumberingSettings::TYPES)]]);

        $branchId = $this->branch($request, $organizationId);

        $validated = $request->validate(
            $this->settings->rules($organizationId, $documentType, $request->input('reset'), $branchId),
            NumberingSettings::messages(),
        );

        $this->settings->save($organizationId, $documentType, $validated, $branchId);

        return ApiResponse::success([
            'sequences' => $this->settings->sequences($organizationId, $branchId),
        ], 'Numbering saved');
    }

    /**
     * The branch a request names, if it is one of the organization's.
     */
    private function branch(Request $request, string $organizationId): ?string
    {
        $branchId = $request->input('branch_id');

        if ($branchId === null || $branchId === '') {
            return null;
        }

        $request->validate(['branch_id' => ['uuid']]);

        abort_unless($this->branches->belongsTo((string) $branchId, $organizationId), 422, 'That branch is not one of this organization\'s.');

        return (string) $branchId;
    }
}
