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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OpportunityController extends Controller
{
    public function __construct(
        private OpportunityService $opportunityService
    ) {}

    /**
     * List opportunities with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
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
            ->orderBy($request->sort_by ?? 'expected_close_date', $request->sort_order ?? 'asc');

        $opportunities = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return OpportunityResource::collection($opportunities);
    }

    /**
     * Store a new opportunity.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opportunity_number' => 'nullable|string|max:50',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'contact_id' => 'nullable|exists:contacts,id',
            'lead_id' => 'nullable|exists:leads,id',
            'account_name' => 'nullable|string|max:200',
            'pipeline_stage_id' => 'nullable|exists:pipeline_stages,id',
            'probability' => 'nullable|integer|min:0|max:100',
            'amount' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'branch_id' => 'nullable|exists:branches,id',
            'lead_source_id' => 'nullable|exists:lead_sources,id',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'competitors' => 'nullable|array',
        ]);

        $opportunity = $this->opportunityService->create($validated);

        return response()->json([
            'message' => 'Opportunity created successfully.',
            'data' => new OpportunityResource($opportunity),
        ], 201);
    }

    /**
     * Show a specific opportunity.
     */
    public function show(Opportunity $opportunity): OpportunityResource
    {
        return new OpportunityResource(
            $opportunity->load(['contact', 'lead', 'pipelineStage', 'assignee', 'leadSource', 'activities'])
        );
    }

    /**
     * Update an opportunity.
     */
    public function update(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'contact_id' => 'nullable|exists:contacts,id',
            'account_name' => 'nullable|string|max:200',
            'probability' => 'nullable|integer|min:0|max:100',
            'amount' => 'nullable|numeric|min:0',
            'expected_close_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'competitors' => 'nullable|array',
        ]);

        $opportunity = $this->opportunityService->update($opportunity, $validated);

        return response()->json([
            'message' => 'Opportunity updated successfully.',
            'data' => new OpportunityResource($opportunity),
        ]);
    }

    /**
     * Delete an opportunity.
     */
    public function destroy(Opportunity $opportunity): JsonResponse
    {
        $opportunity->activities()->delete();
        $opportunity->delete();

        return response()->json(['message' => 'Opportunity deleted successfully.']);
    }

    /**
     * Move opportunity to a different stage.
     */
    public function moveToStage(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'pipeline_stage_id' => 'required|exists:pipeline_stages,id',
        ]);

        $stage = PipelineStage::findOrFail($validated['pipeline_stage_id']);
        $opportunity = $this->opportunityService->moveToStage($opportunity, $stage);

        return response()->json([
            'message' => 'Opportunity moved to new stage.',
            'data' => new OpportunityResource($opportunity),
        ]);
    }

    /**
     * Mark opportunity as won.
     */
    public function win(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $opportunity = $this->opportunityService->win($opportunity, $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Opportunity marked as won.',
            'data' => new OpportunityResource($opportunity),
        ]);
    }

    /**
     * Mark opportunity as lost.
     */
    public function lose(Request $request, Opportunity $opportunity): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $opportunity = $this->opportunityService->lose($opportunity, $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Opportunity marked as lost.',
            'data' => new OpportunityResource($opportunity),
        ]);
    }

    /**
     * Reopen a closed opportunity.
     */
    public function reopen(Opportunity $opportunity): JsonResponse
    {
        $opportunity = $this->opportunityService->reopen($opportunity);

        return response()->json([
            'message' => 'Opportunity reopened.',
            'data' => new OpportunityResource($opportunity),
        ]);
    }

    /**
     * Get pipeline summary.
     */
    public function pipeline(): JsonResponse
    {
        $pipeline = $this->opportunityService->getPipelineSummary();

        return response()->json(['data' => $pipeline]);
    }

    /**
     * Get opportunity statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->opportunityService->getStatistics(
            $request->assigned_to ? (int) $request->assigned_to : null
        );

        return response()->json(['data' => $stats]);
    }

    /**
     * Get sales forecast.
     */
    public function forecast(Request $request): JsonResponse
    {
        $months = (int) ($request->months ?? 6);
        $forecast = $this->opportunityService->getForecast(min($months, 12));

        return response()->json(['data' => $forecast]);
    }
}
