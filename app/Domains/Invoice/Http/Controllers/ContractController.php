<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Services\ProgressBilling;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contracts billed in stages with retention, through the API
 * (ProgressBilling). Each invoice is drafted; it is issued like any draft.
 *
 * GET/POST /api/contracts, GET /api/contracts/{id} (with its statement),
 * POST /api/contracts/{id}/stages/{stage}/invoice, POST
 * /api/contracts/{id}/release; the partner API's /v1/... the same.
 */
class ContractController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ProgressBilling $billing,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(['contracts' => $this->billing->list((string) $this->tenant->getOrganizationId())]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $contract = $this->billing->create($organization, $request->validate($this->billing->contractRules($organization)));

        return ApiResponse::created($this->present($contract), 'Contract recorded');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->contract($id)));
    }

    /** POST /api/contracts/{id}/stages/{stage}/invoice {issue_date?, due_date?} */
    public function invoiceStage(Request $request, string $id, string $stage): JsonResponse
    {
        $dates = $this->dates($request);
        $contract = $this->contract($id);
        $invoice = $this->billing->invoiceStage($this->tenant->getOrganization() ?? abort(404), $contract, $stage, ...$dates);

        return ApiResponse::created(['invoice' => $invoice->load('lines'), ...$this->present($contract->fresh())], 'Stage invoice drafted. Issue it as any draft.');
    }

    /** POST /api/contracts/{id}/release {amount?, issue_date?, due_date?}: all that is held when no amount is given. */
    public function release(Request $request, string $id): JsonResponse
    {
        $amount = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2']])['amount'] ?? null;
        $dates = $this->dates($request);
        $contract = $this->contract($id);
        $invoice = $this->billing->release($this->tenant->getOrganization() ?? abort(404), $contract, $amount === null ? null : (string) $amount, ...$dates);

        return ApiResponse::created(['invoice' => $invoice->load('lines'), ...$this->present($contract->fresh())], 'Retention release drafted. Issue it as any draft.');
    }

    private function contract(string $id): Contract
    {
        return $this->billing->find((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable|null} */
    private function dates(Request $request): array
    {
        $dates = $request->validate(['issue_date' => ['nullable', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:issue_date']]);

        return [
            isset($dates['issue_date']) ? CarbonImmutable::parse($dates['issue_date']) : CarbonImmutable::today(),
            isset($dates['due_date']) ? CarbonImmutable::parse($dates['due_date']) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Contract $contract): array
    {
        return ['contract' => $contract->only(['id', 'customer_id', 'reference', 'title', 'title_ar', 'value', 'retention_percent', 'tax_category', 'tax_rate', 'exempt_code']), 'statement' => $this->billing->statement($contract)];
    }
}
