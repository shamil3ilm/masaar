<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\CRM;

use App\Http\Controllers\Controller;
use App\Http\Resources\CRM\OpportunityResource;
use App\Models\CRM\Opportunity;
use App\Models\CRM\PipelineStage;
use App\Services\CRM\OpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpportunityController extends Controller
{
    public function __construct(
        private OpportunityService $opportunityService
    ) {
    }

    /**
     * List opportunities with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Opportunity::with(['contact', 'pipelineStage', 'assignee', 'leadSource'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->pipeline_stage_id, fn($q, $id) => $q->inStage($id))
            ->when($request->assigned_to, fn($q, $id) => $q->assignedTo($id))
            ->when($request->contact_id, fn($q, $id) => $q->forContact($id))
            ->when($request->open === 'true', fn($q) => $q->open())
            ->when($request->closing_this_month === 'true', fn($q) => $q->closingThisMonth())
            ->when($request->overdue === 'true', fn($q) => $q->overdue())
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('opportunity_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%");
                });
            })
            ->orderBy(
                $this->safeSortBy($request->sort_by, ['title', 'status', 'amount', 'expected_close_date', 'created_at', 'updated_at'], 'expected_close_date'),
                $this->safeSortOrder($request->sort_order, 'asc')
            );

        $opportunities = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($opportunities, OpportunityResource::class);
    }

    /**
     * Store a new opportunity.
     */
    public function store(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'opportunity_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', $orgId)],
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('organization_id', $orgId)],
            'account_name' => 'nullable|string|max:200',
            'pipeline_stage_id' => ['required', Rule::exists('pipeline_stages', 'id')->where('organization_id', $orgId)],
            'probability' => 'nullable|integer|min:0|max:100',
            'amount' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('organization_id', $orgId)],
            'lead_source_id' => ['nullable', Rule::exists('lead_sources', 'id')->where('organization_id', $orgId)],
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'competitors' => 'nullable|array',
        ]);

        $opportunity = $this->opportunityService->create($validated);

        return $this->created(new OpportunityResource($opportunity), 'Opportunity created successfully.');
    }

    /**
     * Show a specific opportunity.
     */
    public function show(Opportunity $opportunity): JsonResponse
    {
        return $this->success(new OpportunityResource(
            $opportunity->load(['contact', 'lead', 'pipelineStage', 'assignee', 'leadSource', 'activities'])
        ));
    }

    /**
     * Update an opportunity.
     */
    public function update(Request $request, Opportunity $opportunity): JsonResponse
    {
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', $orgId)],
            'account_name' => 'nullable|string|max:200',
            'probability' => 'nullable|integer|min:0|max:100',
            'amount' => 'nullable|numeric|min:0',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'competitors' => 'nullable|array',
        ]);

        return $this->tryAction(
            fn() => new OpportunityResource($this->opportunityService->update($opportunity, $validated)),
            'Opportunity updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Delete an opportunity.
     */
    public function destroy(Opportunity $opportunity): JsonResponse
    {
        $opportunity->activities()->delete();
        $opportunity->delete();

        return $this->success(null, 'Opportunity deleted successfully.');
    }

    /**
     * Move opportunity to a different stage.
     */
    public function moveToStage(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'pipeline_stage_id' => ['required', Rule::exists('pipeline_stages', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        return $this->tryAction(
            function () use ($validated, $opportunity) {
                $stage = PipelineStage::findOrFail($validated['pipeline_stage_id']);
                return new OpportunityResource($this->opportunityService->moveToStage($opportunity, $stage, auth()->id()));
            },
            'Opportunity moved to new stage.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Mark opportunity as won.
     */
    public function win(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'won_reason' => 'nullable|string|max:500',
        ]);

        $reason = $validated['won_reason'] ?? $validated['reason'] ?? null;

        return $this->tryAction(
            fn() => new OpportunityResource($this->opportunityService->win($opportunity, auth()->id(), $reason)),
            'Opportunity marked as won.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Mark opportunity as lost.
     */
    public function lose(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'lost_reason' => 'nullable|string|max:500',
        ]);

        $reason = $validated['lost_reason'] ?? $validated['reason'] ?? null;

        return $this->tryAction(
            fn() => new OpportunityResource($this->opportunityService->lose($opportunity, auth()->id(), $reason)),
            'Opportunity marked as lost.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Reopen a closed opportunity.
     */
    public function reopen(Opportunity $opportunity): JsonResponse
    {
        return $this->tryAction(
            fn() => new OpportunityResource($this->opportunityService->reopen($opportunity, auth()->id())),
            'Opportunity reopened.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Get pipeline summary.
     */
    public function pipeline(): JsonResponse
    {
        $pipeline = $this->opportunityService->getPipelineSummary();

        return $this->success($pipeline);
    }

    /**
     * Get opportunity statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->opportunityService->getStatistics(
            $request->assigned_to ? (int) $request->assigned_to : null
        );

        return $this->success($stats);
    }

    /**
     * Get sales forecast.
     */
    public function forecast(Request $request): JsonResponse
    {
        $months = (int) ($request->months ?? 6);
        $forecast = $this->opportunityService->getForecast(min($months, 12));

        return $this->success($forecast);
    }
}
