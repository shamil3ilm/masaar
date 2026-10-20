<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Invoice\Http\Requests\SplitSaleRequest;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Services\InsuranceSplits;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        return ApiResponse::created(self::present($split), 'Drafted: the invoice to the recipient, with the insurer\'s share beside it');
    }

    public function show(string $id): JsonResponse
    {
        $split = $this->splits->find((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);

        return ApiResponse::success(self::present($split));
    }

    /**
     * The insurer's decision (InsuranceSplits::settle()): what it pays of
     * its share of an invoice to the patient, with VAT; or, when it is the
     * recipient, the value it accepts before VAT and why it accepts less -
     * not_covered (credited, and billed to the patient) or price (credited).
     *
     * POST /api/invoices/splits/{id}/settle {approved, decision?, adjustment_reason?}
     */
    public function settle(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'approved' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'decision' => ['nullable', Rule::in([InsuranceSplit::NOT_COVERED, InsuranceSplit::PRICE])],
            // One of the reasons the authority lists (KSA-10); the one for a
            // supply cancelled wholly or partly when none is given.
            'adjustment_reason' => ['nullable', 'string', Rule::in([...AdjustmentReason::values(), ...array_keys(AdjustmentReason::OFFICIAL)])],
        ]);

        $organization = $this->tenant->getOrganization() ?? abort(404);
        $split = $this->splits->find((string) $organization->id, $id) ?? abort(404);
        $reason = AdjustmentReason::bilingual($validated['adjustment_reason'] ?? null) ?? ($validated['adjustment_reason'] ?? null);

        $outcomes = $this->splits->settle($split, $organization, (string) $validated['approved'], $validated['decision'] ?? null, $reason, new IssueOptions(file: true));
        $errors = [...($outcomes['credit']?->errors ?? []), ...($outcomes['rebill']?->errors ?? [])];

        return ApiResponse::success(
            [...self::present($split->fresh()), ...($errors === [] ? [] : ['errors' => $errors])],
            $errors === [] ? 'The insurer\'s decision is recorded' : 'The decision is recorded; a document was not filed cleanly. See errors.',
            $errors === [] ? 200 : 207,
        );
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
