<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\CRM;

use App\Http\Controllers\Controller;
use App\Http\Resources\CRM\LeadResource;
use App\Models\CRM\Lead;
use App\Services\CRM\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $leadService
    ) {}

    /**
     * List leads with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Lead::with(['leadSource', 'assignee', 'branch'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->rating, fn($q, $rating) => $q->withRating($rating))
            ->when($request->lead_source_id, fn($q, $id) => $q->fromSource($id))
            ->when($request->assigned_to, fn($q, $id) => $q->assignedTo($id))
            ->when($request->open === 'true', fn($q) => $q->open())
            ->when($request->hot === 'true', fn($q) => $q->hot())
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('lead_number', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc');

        $leads = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return LeadResource::collection($leads);
    }

    /**
     * Store a new lead.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_number' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:200',
            'lead_type' => 'nullable|in:individual,company',
            'company_name' => 'nullable|string|max:200',
            'industry' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:200',
            'employee_count' => 'nullable|integer|min:0',
            'annual_revenue' => 'nullable|numeric|min:0',
            'contact_name' => 'required|string|max:200',
            'contact_title' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:200',
            'address_line_2' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|size:2',
            'lead_source_id' => 'nullable|exists:lead_sources,id',
            'source_details' => 'nullable|string|max:200',
            'assigned_to' => 'nullable|exists:users,id',
            'branch_id' => 'nullable|exists:branches,id',
            'rating' => 'nullable|in:hot,warm,cold',
            'estimated_value' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $lead = $this->leadService->create($validated);

        return response()->json([
            'message' => 'Lead created successfully.',
            'data' => new LeadResource($lead),
        ], 201);
    }

    /**
     * Show a specific lead.
     */
    public function show(Lead $lead): LeadResource
    {
        return new LeadResource(
            $lead->load(['leadSource', 'assignee', 'branch', 'activities', 'convertedContact', 'convertedOpportunity'])
        );
    }

    /**
     * Update a lead.
     */
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:200',
            'lead_type' => 'nullable|in:individual,company',
            'company_name' => 'nullable|string|max:200',
            'industry' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:200',
            'employee_count' => 'nullable|integer|min:0',
            'annual_revenue' => 'nullable|numeric|min:0',
            'contact_name' => 'sometimes|string|max:200',
            'contact_title' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'address_line_1' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'lead_source_id' => 'nullable|exists:lead_sources,id',
            'assigned_to' => 'nullable|exists:users,id',
            'rating' => 'nullable|in:hot,warm,cold',
            'estimated_value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $lead = $this->leadService->update($lead, $validated);

        return response()->json([
            'message' => 'Lead updated successfully.',
            'data' => new LeadResource($lead),
        ]);
    }

    /**
     * Delete a lead.
     */
    public function destroy(Lead $lead): JsonResponse
    {
        if ($lead->isConverted()) {
            return response()->json(['message' => 'Converted leads cannot be deleted.'], 422);
        }

        $lead->activities()->delete();
        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully.']);
    }

    /**
     * Change lead status.
     */
    public function changeStatus(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:new,contacted,qualified,unqualified,lost',
            'reason' => 'nullable|string|max:500',
        ]);

        $lead = $this->leadService->changeStatus($lead, $validated['status'], $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Lead status changed successfully.',
            'data' => new LeadResource($lead),
        ]);
    }

    /**
     * Convert lead to customer/opportunity.
     */
    public function convert(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'create_opportunity' => 'nullable|boolean',
            'opportunity_name' => 'nullable|string|max:200',
            'opportunity_amount' => 'nullable|numeric|min:0',
            'expected_close_date' => 'nullable|date',
        ]);

        $result = $this->leadService->convert(
            $lead,
            $validated['create_opportunity'] ?? true,
            [
                'name' => $validated['opportunity_name'] ?? null,
                'amount' => $validated['opportunity_amount'] ?? null,
                'expected_close_date' => $validated['expected_close_date'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Lead converted successfully.',
            'data' => [
                'lead' => new LeadResource($result['lead']),
                'contact' => $result['contact'],
                'opportunity' => $result['opportunity'],
            ],
        ]);
    }

    /**
     * Assign lead to user.
     */
    public function assign(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $lead = $this->leadService->assign($lead, $validated['user_id']);

        return response()->json([
            'message' => 'Lead assigned successfully.',
            'data' => new LeadResource($lead),
        ]);
    }

    /**
     * Get lead statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->leadService->getStatistics(
            $request->assigned_to ? (int) $request->assigned_to : null
        );

        return response()->json(['data' => $stats]);
    }
}
