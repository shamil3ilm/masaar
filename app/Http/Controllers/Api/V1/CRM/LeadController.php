<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\CRM;

use App\Http\Controllers\Controller;
use App\Http\Resources\CRM\LeadResource;
use App\Models\CRM\Lead;
use App\Services\CRM\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $leadService
    ) {
    }

    /**
     * List leads with filtering.
     */
    public function index(Request $request): JsonResponse
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
            ->orderBy(
                $this->safeSortBy($request->sort_by, ['title', 'status', 'created_at', 'updated_at', 'expected_close_date', 'lead_value'], 'created_at'),
                $this->safeSortOrder($request->sort_order, 'desc')
            );

        $leads = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($leads, LeadResource::class);
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
            'lead_source_id' => ['nullable', Rule::exists('lead_sources', 'id')->where('organization_id', auth()->user()->organization_id)],
            'source_details' => 'nullable|string|max:200',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('organization_id', auth()->user()->organization_id)],
            'rating' => 'nullable|in:hot,warm,cold',
            'estimated_value' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:new,contacted,qualified,unqualified,converted,lost',
        ]);

        $lead = $this->leadService->create($validated, auth()->id());

        return $this->created(new LeadResource($lead), 'Lead created successfully.');
    }

    /**
     * Show a specific lead.
     */
    public function show(Lead $lead): JsonResponse
    {
        return $this->success(new LeadResource(
            $lead->load(['leadSource', 'assignee', 'branch', 'activities', 'convertedContact', 'convertedOpportunity'])
        ));
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
            'lead_source_id' => ['nullable', Rule::exists('lead_sources', 'id')->where('organization_id', auth()->user()->organization_id)],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
            'rating' => 'nullable|in:hot,warm,cold',
            'estimated_value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        return $this->tryAction(
            fn() => new LeadResource($this->leadService->update($lead, $validated)),
            'Lead updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Delete a lead.
     */
    public function destroy(Lead $lead): JsonResponse
    {
        if ($lead->isConverted()) {
            return $this->error('Converted leads cannot be deleted.', 'VALIDATION_ERROR', 422);
        }

        $lead->activities()->delete();
        $lead->delete();

        return $this->success(null, 'Lead deleted successfully.');
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

        return $this->tryAction(
            fn() => new LeadResource($this->leadService->changeStatus($lead, $validated['status'], $validated['reason'] ?? null)),
            'Lead status changed successfully.',
            'VALIDATION_ERROR'
        );
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

        try {
            $result = $this->leadService->convert(
                $lead,
                auth()->id(),
                $validated['create_opportunity'] ?? true,
                [
                    'name' => $validated['opportunity_name'] ?? null,
                    'amount' => $validated['opportunity_amount'] ?? null,
                    'expected_close_date' => $validated['expected_close_date'] ?? null,
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        $lead = $result['lead'];
        $leadData = (new LeadResource($lead))->toArray(request());
        $leadData['converted_contact'] = $result['contact'];
        $leadData['converted_opportunity'] = $result['opportunity'];

        return $this->success($leadData, 'Lead converted successfully.');
    }

    /**
     * Assign lead to user.
     */
    public function assign(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('organization_id', auth()->user()->organization_id)],
        ]);

        $lead = $this->leadService->assign($lead, $validated['user_id'], auth()->id());

        return $this->success(new LeadResource($lead), 'Lead assigned successfully.');
    }

    /**
     * Get lead statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->leadService->getStatistics(
            $request->assigned_to ? (int) $request->assigned_to : null
        );

        return $this->success($stats);
    }
}
