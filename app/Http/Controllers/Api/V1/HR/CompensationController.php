<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\CompensationReview;
use App\Services\HR\CompensationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompensationController extends Controller
{
    public function __construct(
        private CompensationService $compensationService
    ) {}

    /**
     * List compensation reviews.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'review_date_from', 'review_date_to', 'per_page']);
        $reviews = $this->compensationService->index($filters);

        return $this->paginated($reviews);
    }

    /**
     * Create a new compensation review.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'review_name'    => 'required|string|max:100',
            'review_date'    => 'required|date',
            'effective_date' => 'required|date|after_or_equal:review_date',
            'budget_amount'  => 'nullable|numeric|min:0',
        ]);

        $review = $this->compensationService->createReview($validated);

        return $this->success($review, 'Compensation review created.', 201);
    }

    /**
     * Add an employee item to a review.
     */
    public function addItem(Request $request, CompensationReview $compensationReview): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'current_salary'  => 'nullable|numeric|min:0',
            'proposed_salary' => 'nullable|numeric|min:0',
            'adjustment_type' => 'nullable|in:merit,promotion,market_adjustment,equity',
            'justification'   => 'nullable|string|max:1000',
        ]);

        try {
            $item = $this->compensationService->addItem($compensationReview, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success($item, 'Review item added.', 201);
    }

    /**
     * Bulk-recommend increases for all pending items.
     */
    public function bulkRecommend(Request $request, CompensationReview $compensationReview): JsonResponse
    {
        $validated = $request->validate([
            'increase_percentage' => 'required|numeric|min:0.01|max:100',
        ]);

        try {
            $count = $this->compensationService->bulkRecommend($compensationReview, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }

        return $this->success(['updated_count' => $count], "Bulk recommendation applied to {$count} items.");
    }

    /**
     * Approve a compensation review.
     */
    public function approve(CompensationReview $compensationReview): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->compensationService->approve($compensationReview),
            'Compensation review approved.',
            'STATE_ERROR'
        );
    }

    /**
     * Apply an approved compensation review (update employee salaries).
     */
    public function apply(CompensationReview $compensationReview): JsonResponse
    {
        try {
            $count = $this->compensationService->apply($compensationReview);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'STATE_ERROR', 422);
        }

        return $this->success(['applied_count' => $count], "Salary updates applied for {$count} employees.");
    }
}
