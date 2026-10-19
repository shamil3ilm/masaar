<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Http\Requests\SplitSaleRequest;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A sale billed in two shares through the API: both drafts, linked
 * (InsuranceSplits). Each is then issued like any draft, or both at once
 * through the pipeline's split endpoint.
 *
 * POST /api/invoices/splits and GET /api/invoices/splits/{id}; the partner
 * API's /v1/invoices/splits the same.
 */
class InsuranceSplitController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InsuranceSplits $splits,
        private readonly Tills $tills,
        private readonly BranchAccess $branches,
    ) {}

    /**
     * An Idempotency-Key header makes a retry answer with the split the
     * first request made - 200 and Idempotent-Replayed: true - instead of
     * billing the sale twice.
     */
    public function store(SplitSaleRequest $request): JsonResponse
    {
        $organizationId = (string) $this->tenant->getOrganizationId();
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $key = $request->header('Idempotency-Key') ?: null;

        if ($earlier = $this->splits->createdUnder($organizationId, $key)) {
            return ApiResponse::success(self::present($earlier), 'Split sale already created under this Idempotency-Key')
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            ['branch' => $branchId, 'till' => $tillId] = $this->tills->named(
                $organizationId,
                $request->validated('branch_id'),
                $request->validated('till_id'),
                $request->validated('till_number') === null ? null : (int) $request->validated('till_number'),
            );
        } catch (DomainException $e) {
            return ApiResponse::validationError(['till_id' => [$e->getMessage()]]);
        }

        $userId = $request->user()?->getAuthIdentifier();

        if ($userId !== null && $this->tenant->getRole() !== 'service') {
            try {
                $branchId = $this->branches->draftBranch((string) $userId, $organizationId, $branchId);
            } catch (DomainException $e) {
                return ApiResponse::forbidden($e->getMessage());
            }
        }

        $split = $this->splits->draft($organization, $request->draft(), (array) $request->validated('split'), $branchId, $tillId, $key);

        return ApiResponse::created(self::present($split), 'Split sale drafted: one invoice for the patient, one for the insurer');
    }

    public function show(string $id): JsonResponse
    {
        $split = $this->splits->find((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);

        return ApiResponse::success(self::present($split));
    }

    /**
     * The split and its invoices, by share.
     *
     * @return array<string, mixed>
     */
    public static function present(InsuranceSplit $split): array
    {
        $split->loadMissing('invoices.lines');

        return [
            'split' => $split->summary(),
            'invoices' => $split->invoices->keyBy('split_role')->all(),
        ];
    }
}
