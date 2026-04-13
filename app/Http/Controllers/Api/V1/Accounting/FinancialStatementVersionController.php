<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\FinancialStatementVersion;
use App\Models\Accounting\FinancialStatementVersionNode;
use App\Services\Accounting\FinancialStatementVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinancialStatementVersionController extends Controller
{
    public function __construct(
        private FinancialStatementVersionService $service
    ) {}

    /**
     * List financial statement versions.
     */
    public function index(Request $request): JsonResponse
    {
        $versions = $this->service->list($request->only(['type', 'is_active', 'per_page']));

        return $this->paginated($versions);
    }

    /**
     * Create a financial statement version.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in([
                FinancialStatementVersion::TYPE_BALANCE_SHEET,
                FinancialStatementVersion::TYPE_INCOME_STATEMENT,
                FinancialStatementVersion::TYPE_CASH_FLOW,
            ])],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['created_by'] = auth()->id();

        try {
            $fsv = $this->service->create($validated);
            return $this->created($fsv, 'Financial statement version created successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a financial statement version with its node tree.
     */
    public function show(FinancialStatementVersion $financialStatementVersion): JsonResponse
    {
        $financialStatementVersion->load(['nodes.children.children', 'createdBy']);

        return $this->success($financialStatementVersion);
    }

    /**
     * Update a financial statement version.
     */
    public function update(Request $request, FinancialStatementVersion $financialStatementVersion): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', Rule::in([
                FinancialStatementVersion::TYPE_BALANCE_SHEET,
                FinancialStatementVersion::TYPE_INCOME_STATEMENT,
                FinancialStatementVersion::TYPE_CASH_FLOW,
            ])],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->tryAction(
            fn() => $this->service->update($financialStatementVersion, $validated),
            'Financial statement version updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Delete a financial statement version.
     */
    public function destroy(FinancialStatementVersion $financialStatementVersion): JsonResponse
    {
        $this->service->delete($financialStatementVersion);

        return $this->success(null, 'Financial statement version deleted successfully.');
    }

    /**
     * Add a node to the FSV.
     */
    public function addNode(Request $request, FinancialStatementVersion $financialStatementVersion): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', Rule::exists('financial_statement_version_nodes', 'id')],
            'account_id' => [
                'nullable',
                Rule::exists('chart_of_accounts', 'id')
                    ->where('organization_id', auth()->user()->organization_id),
            ],
            'node_type' => ['required', Rule::in(['header', 'account', 'total'])],
            'label' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'sign' => ['nullable', 'integer', Rule::in([-1, 1])],
        ]);

        try {
            $node = $this->service->addNode($financialStatementVersion, $validated);
            return $this->created($node, 'Node added successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Remove a node from the FSV.
     */
    public function removeNode(
        FinancialStatementVersion $financialStatementVersion,
        FinancialStatementVersionNode $node
    ): JsonResponse {
        if ((int) $node->fsv_id !== $financialStatementVersion->id) {
            return $this->error('Node does not belong to this FSV.', 'NOT_FOUND', 404);
        }

        $this->service->removeNode($node);

        return $this->success(null, 'Node removed successfully.');
    }

    /**
     * Generate the financial statement for a given period.
     */
    public function generate(Request $request, FinancialStatementVersion $financialStatementVersion): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $report = $this->service->generate(
            $financialStatementVersion,
            $validated['period_start'],
            $validated['period_end']
        );

        return $this->success($report);
    }
}
