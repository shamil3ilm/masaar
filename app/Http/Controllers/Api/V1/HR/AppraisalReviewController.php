<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\AppraisalReviewer;
use App\Models\HR\PerformanceAppraisal;
use App\Services\HR\PerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppraisalReviewController extends Controller
{
    public function __construct(
        private PerformanceService $service
    ) {}

    /**
     * POST /hr/appraisals/{appraisal}/reviewers
     *
     * Add one or more reviewers to the appraisal's 360° review cycle.
     */
    public function addReviewers(Request $request, int $appraisal): JsonResponse
    {
        $appraisalModel = PerformanceAppraisal::find($appraisal);

        if ($appraisalModel === null) {
            return $this->notFound('Performance appraisal not found.');
        }

        $validated = $request->validate([
            'reviewers'                    => 'required|array|min:1',
            'reviewers.*.employee_id'      => 'required|integer|exists:employees,id',
            'reviewers.*.type'             => 'required|in:' . implode(',', AppraisalReviewer::TYPES),
            'reviewers.*.is_anonymous'     => 'nullable|boolean',
            'reviewers.*.due_date'         => 'nullable|date',
        ]);

        try {
            $this->service->initiateReviewCycle($appraisalModel, $validated['reviewers']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'REVIEWER_ERROR', 422);
        }

        $reviewers = AppraisalReviewer::forAppraisal($appraisalModel->id)
            ->with('reviewer')
            ->get();

        return $this->success($reviewers, 'Reviewers added successfully.');
    }

    /**
     * GET /hr/appraisals/{appraisal}/reviewers
     *
     * List all reviewers for the appraisal with their current status.
     */
    public function listReviewers(int $appraisal): JsonResponse
    {
        $appraisalModel = PerformanceAppraisal::find($appraisal);

        if ($appraisalModel === null) {
            return $this->notFound('Performance appraisal not found.');
        }

        $reviewers = AppraisalReviewer::forAppraisal($appraisalModel->id)
            ->with('reviewer')
            ->get()
            ->map(function (AppraisalReviewer $r): array {
                $base = $r->toArray();

                // Hide reviewer identity when the record is anonymous and the
                // review has not yet been submitted (still in progress / pending).
                if ($r->is_anonymous && !$r->isSubmitted()) {
                    $base['reviewer'] = null;
                    $base['reviewer_id'] = null;
                }

                return $base;
            });

        return $this->success($reviewers);
    }

    /**
     * POST /hr/appraisals/{appraisal}/reviewers/{reviewer}/submit
     *
     * Submit feedback from a reviewer.
     */
    public function submitReview(Request $request, int $appraisal, int $reviewer): JsonResponse
    {
        $appraisalModel = PerformanceAppraisal::find($appraisal);

        if ($appraisalModel === null) {
            return $this->notFound('Performance appraisal not found.');
        }

        $reviewerModel = AppraisalReviewer::forAppraisal($appraisalModel->id)->find($reviewer);

        if ($reviewerModel === null) {
            return $this->notFound('Reviewer record not found.');
        }

        $validated = $request->validate([
            'overall_rating'              => 'nullable|numeric|min:0|max:5',
            'strengths'                   => 'nullable|string|max:3000',
            'improvements'                => 'nullable|string|max:3000',
            'comments'                    => 'nullable|string|max:3000',
            'responses'                   => 'nullable|array',
            'responses.*.question_id'     => 'nullable|integer|exists:appraisal_template_questions,id',
            'responses.*.question_text'   => 'nullable|string|max:1000',
            'responses.*.rating'          => 'nullable|numeric|min:0|max:5',
            'responses.*.response_text'   => 'nullable|string|max:2000',
        ]);

        return $this->tryAction(
            fn() => $this->service->submitReview($reviewerModel, $validated, auth()->id()),
            'Review submitted successfully.',
            'SUBMIT_REVIEW_ERROR'
        );
    }

    /**
     * POST /hr/appraisals/{appraisal}/reviewers/{reviewer}/decline
     *
     * Decline a reviewer's invitation to provide feedback.
     */
    public function declineReview(int $appraisal, int $reviewer): JsonResponse
    {
        $appraisalModel = PerformanceAppraisal::find($appraisal);

        if ($appraisalModel === null) {
            return $this->notFound('Performance appraisal not found.');
        }

        $reviewerModel = AppraisalReviewer::forAppraisal($appraisalModel->id)->find($reviewer);

        if ($reviewerModel === null) {
            return $this->notFound('Reviewer record not found.');
        }

        return $this->tryAction(
            fn() => $this->service->declineReview($reviewerModel),
            'Review declined.',
            'DECLINE_REVIEW_ERROR'
        );
    }

    /**
     * GET /hr/appraisals/{appraisal}/aggregate-ratings
     *
     * Return aggregated ratings from all submitted 360° reviewers.
     */
    public function aggregateRatings(int $appraisal): JsonResponse
    {
        $appraisalModel = PerformanceAppraisal::find($appraisal);

        if ($appraisalModel === null) {
            return $this->notFound('Performance appraisal not found.');
        }

        $ratings    = $this->service->getAggregatedRatings($appraisalModel);
        $completion = $this->service->getCompletionStatus($appraisalModel);

        return $this->success([
            'ratings'    => $ratings,
            'completion' => $completion,
        ]);
    }
}
