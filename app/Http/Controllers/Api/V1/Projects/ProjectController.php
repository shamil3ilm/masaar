<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\Projects\ProjectCostEntry;
use App\Models\Projects\ProjectMember;
use App\Models\Projects\ProjectMilestone;
use App\Models\Projects\ProjectTimeEntry;
use App\Models\Projects\WbsElement;
use App\Services\Projects\CriticalPathService;
use App\Services\Projects\EarnedValueService;
use App\Services\Projects\ProjectCostReportingService;
use App\Services\Projects\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Projects CRUD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List projects with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Project::with(['manager', 'customer', 'branch'])
            ->withCount(['milestones', 'members', 'allWbsElements'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->priority, fn ($q, $v) => $q->where('priority', $v))
            ->when($request->project_type, fn ($q, $v) => $q->where('project_type', $v))
            ->when($request->manager_id, fn ($q, $v) => $q->where('manager_id', $v))
            ->when($request->customer_id, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('project_number', 'like', "%{$search}%");
                });
            })
            ->orderBy(
                $this->safeSortBy(
                    $request->sort_by,
                    ['project_number', 'name', 'status', 'priority', 'start_date', 'end_date', 'created_at'],
                    'created_at'
                ),
                $this->safeSortOrder($request->sort_order, 'desc')
            );

        $projects = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($projects, fn ($p) => $p);
    }

    /**
     * Create a new project.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_type' => 'nullable|in:internal,customer,rd,capital',
            'customer_id' => 'nullable|exists:sales_contacts,id',
            'priority' => 'nullable|in:low,medium,high,critical',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'manager_id' => 'nullable|exists:hr_employees,id',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        try {
            $project = $this->projectService->createProject($validated, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($project->load(['manager', 'customer', 'branch']), 'Project created successfully.');
    }

    /**
     * Show a single project with full relations.
     */
    public function show(Project $project): JsonResponse
    {
        $project->load([
            'manager',
            'customer',
            'branch',
            'wbsElements.children',
            'milestones',
            'members.employee',
            'createdBy',
        ]);

        return $this->success($project);
    }

    /**
     * Update a project.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'project_type' => 'nullable|in:internal,customer,rd,capital',
            'customer_id' => 'nullable|exists:sales_contacts,id',
            'status' => 'nullable|in:draft,planning,active,on_hold,completed,cancelled',
            'priority' => 'nullable|in:low,medium,high,critical',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'budget' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'manager_id' => 'nullable|exists:hr_employees,id',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        return $this->tryAction(
            fn() => $this->projectService->updateProject($project, $validated)->fresh(['manager', 'customer']),
            'Project updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Delete a project (soft).
     */
    public function destroy(Project $project): JsonResponse
    {
        if ($project->isActive()) {
            return $this->error('Cannot delete an active project.', 'VALIDATION_ERROR', 422);
        }

        $project->delete();

        return $this->success(null, 'Project deleted successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Project Actions
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Activate a project.
     */
    public function activateProject(Project $project): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->projectService->activateProject($project, auth()->id()),
            'Project activated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Complete a project.
     */
    public function completeProject(Project $project): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->projectService->completeProject($project, auth()->id()),
            'Project completed successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Project dashboard summary.
     */
    public function dashboard(Project $project): JsonResponse
    {
        $dashboard = $this->projectService->getProjectDashboard($project);

        return $this->success($dashboard);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WBS Elements
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List all WBS elements for a project (tree structure).
     */
    public function wbsIndex(Project $project): JsonResponse
    {
        $elements = WbsElement::where('project_id', $project->id)
            ->whereNull('parent_id')
            ->with(['children.children', 'responsible'])
            ->orderBy('sort_order')
            ->get();

        return $this->success($elements);
    }

    /**
     * Create a WBS element.
     */
    public function wbsStore(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:wbs_elements,id',
            'wbs_code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:created,released,technically_complete,closed',
            'planned_start_date' => 'nullable|date',
            'planned_end_date' => 'nullable|date|after_or_equal:planned_start_date',
            'planned_cost' => 'nullable|numeric|min:0',
            'planned_revenue' => 'nullable|numeric|min:0',
            'responsible_employee_id' => 'nullable|exists:hr_employees,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $element = $this->projectService->createWbsElement($project, $validated, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($element->load(['responsible', 'parent']), 'WBS element created successfully.');
    }

    /**
     * Show a single WBS element.
     */
    public function wbsShow(Project $project, WbsElement $wbsElement): JsonResponse
    {
        if ($wbsElement->project_id !== $project->id) {
            return $this->notFound('WBS element not found for this project.');
        }

        return $this->success($wbsElement->load(['children', 'parent', 'responsible', 'timeEntries', 'costEntries']));
    }

    /**
     * Update a WBS element.
     */
    public function wbsUpdate(Request $request, Project $project, WbsElement $wbsElement): JsonResponse
    {
        if ($wbsElement->project_id !== $project->id) {
            return $this->notFound('WBS element not found for this project.');
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:created,released,technically_complete,closed',
            'planned_start_date' => 'nullable|date',
            'planned_end_date' => 'nullable|date',
            'planned_cost' => 'nullable|numeric|min:0',
            'planned_revenue' => 'nullable|numeric|min:0',
            'responsible_employee_id' => 'nullable|exists:hr_employees,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $wbsElement->update($validated);

        return $this->success($wbsElement->fresh(['responsible']), 'WBS element updated successfully.');
    }

    /**
     * Update progress on a WBS element.
     */
    public function updateProgress(Request $request, Project $project, WbsElement $wbsElement): JsonResponse
    {
        if ($wbsElement->project_id !== $project->id) {
            return $this->notFound('WBS element not found for this project.');
        }

        $validated = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
        ]);

        return $this->tryAction(
            fn() => $this->projectService->updateProgress($wbsElement, $validated['progress_percent'], auth()->id()),
            'Progress updated successfully.',
            'VALIDATION_ERROR'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Milestones
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List milestones for a project.
     */
    public function milestonesIndex(Request $request, Project $project): JsonResponse
    {
        $milestones = ProjectMilestone::where('project_id', $project->id)
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->with(['wbsElement'])
            ->orderBy('due_date')
            ->get();

        return $this->success($milestones);
    }

    /**
     * Create a milestone.
     */
    public function milestonesStore(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'required|date',
            'wbs_element_id' => 'nullable|exists:wbs_elements,id',
            'notes' => 'nullable|string',
        ]);

        $validated['project_id'] = $project->id;

        try {
            $milestone = $this->projectService->createMilestone($validated, auth()->id());
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($milestone->load(['wbsElement']), 'Milestone created successfully.');
    }

    /**
     * Update a milestone.
     */
    public function milestonesUpdate(Request $request, ProjectMilestone $milestone): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'sometimes|date',
            'notes' => 'nullable|string',
            'wbs_element_id' => 'nullable|exists:wbs_elements,id',
        ]);

        $milestone->update($validated);

        return $this->success($milestone->fresh(['wbsElement']), 'Milestone updated successfully.');
    }

    /**
     * Achieve a milestone.
     */
    public function achieveMilestone(ProjectMilestone $milestone): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->projectService->achieveMilestone($milestone, auth()->id()),
            'Milestone achieved.',
            'VALIDATION_ERROR'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Time Entries
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List time entries for a project.
     */
    public function timeEntriesIndex(Request $request, Project $project): JsonResponse
    {
        $query = ProjectTimeEntry::where('project_id', $project->id)
            ->with(['employee', 'wbsElement', 'approvedBy'])
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->wbs_element_id, fn ($q, $v) => $q->where('wbs_element_id', $v))
            ->when($request->approved === 'true', fn ($q) => $q->whereNotNull('approved_by'))
            ->when($request->approved === 'false', fn ($q) => $q->whereNull('approved_by'))
            ->orderBy('work_date', 'desc');

        $entries = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($entries, fn ($e) => $e);
    }

    /**
     * Log a time entry.
     */
    public function timeEntriesStore(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:hr_employees,id',
            'work_date' => 'required|date',
            'hours' => 'required|numeric|min:0.25|max:24',
            'description' => 'nullable|string|max:500',
            'wbs_element_id' => 'nullable|exists:wbs_elements,id',
            'is_billable' => 'nullable|boolean',
            'hourly_rate' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
        ]);

        $validated['project_id'] = $project->id;

        try {
            $entry = $this->projectService->logTime($validated, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($entry, 'Time entry logged successfully.');
    }

    /**
     * Approve a time entry.
     */
    public function approveTime(ProjectTimeEntry $timeEntry): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->projectService->approveTime($timeEntry, auth()->id()),
            'Time entry approved.',
            'VALIDATION_ERROR'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cost Entries
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List cost entries for a project.
     */
    public function costEntriesIndex(Request $request, Project $project): JsonResponse
    {
        $query = ProjectCostEntry::where('project_id', $project->id)
            ->with(['wbsElement'])
            ->when($request->cost_type, fn ($q, $v) => $q->where('cost_type', $v))
            ->when($request->wbs_element_id, fn ($q, $v) => $q->where('wbs_element_id', $v))
            ->orderBy('cost_date', 'desc');

        $entries = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($entries, fn ($e) => $e);
    }

    /**
     * Add a cost entry.
     */
    public function costEntriesStore(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'cost_type' => 'required|in:labor,material,equipment,subcontract,overhead,other',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0.01',
            'currency_code' => 'nullable|string|size:3',
            'cost_date' => 'required|date',
            'wbs_element_id' => 'nullable|exists:wbs_elements,id',
            'reference_type' => 'nullable|string|max:255',
            'reference_id' => 'nullable|integer',
            'journal_entry_id' => 'nullable|exists:accounting_journal_entries,id',
        ]);

        $validated['project_id'] = $project->id;

        try {
            $entry = $this->projectService->addCostEntry($validated, auth()->id());
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($entry, 'Cost entry added successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Members
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * List project members.
     */
    public function membersIndex(Project $project): JsonResponse
    {
        $members = ProjectMember::where('project_id', $project->id)
            ->with(['employee'])
            ->get();

        return $this->success($members);
    }

    /**
     * Add a member to a project.
     */
    public function membersStore(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:hr_employees,id',
            'role' => 'nullable|in:manager,member,reviewer,sponsor',
        ]);

        try {
            $member = $this->projectService->addMember(
                $project,
                $validated['employee_id'],
                $validated['role'] ?? ProjectMember::ROLE_MEMBER,
                auth()->id()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred.', 'SERVER_ERROR', 500);
        }

        return $this->created($member, 'Member added successfully.');
    }

    /**
     * Remove a project member.
     */
    public function membersDestroy(ProjectMember $member): JsonResponse
    {
        $this->projectService->removeMember($member);

        return $this->success(null, 'Member removed successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gap 1: Critical Path Method (CPM) Scheduling
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run full CPM forward/backward pass and return activities with float + critical path.
     */
    public function criticalPath(Request $request, Project $project): JsonResponse
    {
        $result = app(CriticalPathService::class)->calculate($project->id);

        return $this->success($result);
    }

    /**
     * Forecast project schedule completion based on critical-path progress.
     */
    public function scheduleForecast(Request $request, Project $project): JsonResponse
    {
        $result = app(CriticalPathService::class)->forecastCompletion($project->id);

        return $this->success($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gap 2: Project Cost Variance Reports
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Planned vs actual cost variance by WBS element and cost type.
     */
    public function costVariance(Request $request, Project $project): JsonResponse
    {
        $report = app(ProjectCostReportingService::class)->getVarianceReport($project->id);

        return $this->success($report);
    }

    /**
     * Monthly cost trend with cumulative totals.
     */
    public function costTrend(Request $request, Project $project): JsonResponse
    {
        $months = max(1, $request->integer('months', 12));
        $report = app(ProjectCostReportingService::class)->getCostTrend($project->id, $months);

        return $this->success($report);
    }

    /**
     * Cost breakdown by type (labor, material, equipment, etc.).
     */
    public function costByType(Request $request, Project $project): JsonResponse
    {
        $report = app(ProjectCostReportingService::class)->getCostByType($project->id);

        return $this->success($report);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gap 3: EVM Trending & Forecasting
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute and persist an EVM snapshot for today.
     */
    public function evmSnapshot(Request $request, Project $project): JsonResponse
    {
        $snapshot = app(EarnedValueService::class)->computeSnapshot($project->id);

        return $this->success($snapshot);
    }

    /**
     * Return EVM trend (last N snapshots) with health status and forecast.
     */
    public function evmTrend(Request $request, Project $project): JsonResponse
    {
        $snapshots = max(1, $request->integer('snapshots', 12));
        $trend     = app(EarnedValueService::class)->getTrend($project->id, $snapshots);

        return $this->success($trend);
    }
}
