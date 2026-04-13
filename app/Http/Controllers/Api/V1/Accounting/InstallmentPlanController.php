<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\InstallmentPlan;
use App\Models\Accounting\InstallmentSchedule;
use App\Services\Accounting\InstallmentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstallmentPlanController extends Controller
{
    public function __construct(
        private readonly InstallmentPlanService $installmentService,
    ) {}

    /** List all installment plans for the organisation. */
    public function index(Request $request): JsonResponse
    {
        $plans = $this->installmentService->listPlans($this->organizationId($request), $request->all());

        return $this->paginated($plans);
    }

    /** Create a new installment plan (equal schedule or custom). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type'     => ['required', 'in:invoice,bill,sales_order'],
            'document_id'       => ['required', 'integer'],
            'contact_id'        => ['nullable', 'integer'],
            'total_amount'      => ['required', 'numeric', 'min:0.01'],
            'currency_code'     => ['nullable', 'string', 'size:3'],
            'start_date'        => ['required', 'date'],
            'installment_count' => ['required', 'integer', 'min:2'],
            'frequency_days'    => ['sometimes', 'integer', 'min:1'],
            'notes'             => ['nullable', 'string', 'max:500'],
            // Optional custom schedule (overrides equal split)
            'schedules'         => ['sometimes', 'array', 'min:2'],
            'schedules.*.amount'   => ['required', 'numeric', 'min:0.01'],
            'schedules.*.due_date' => ['required', 'date'],
            'schedules.*.notes'    => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $plan = $this->installmentService->create(
                $validated,
                $this->organizationId($request),
                $request->user()->id
            );

            return $this->created($plan);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_FAILED', 422);
        }
    }

    /** Show a single plan with its full schedule. */
    public function show(InstallmentPlan $installmentPlan): JsonResponse
    {
        $installmentPlan->load(['schedules', 'contact:id,company_name,contact_name']);

        return $this->success($installmentPlan);
    }

    /** Activate a draft plan. */
    public function activate(InstallmentPlan $installmentPlan): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->installmentService->activate($installmentPlan),
            'Installment plan activated.',
            'ACTIVATE_FAILED',
        );
    }

    public function cancel(Request $request, InstallmentPlan $installmentPlan): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->tryAction(
            fn() => $this->installmentService->cancel($installmentPlan, $validated['reason'] ?? ''),
            'Installment plan cancelled.',
            'CANCEL_FAILED',
        );
    }

    /** Record a payment against a specific schedule line. */
    public function recordPayment(Request $request, InstallmentPlan $installmentPlan, InstallmentSchedule $installmentSchedule): JsonResponse
    {
        $validated = $request->validate([
            'paid_amount'  => ['required', 'numeric', 'min:0.01'],
            'paid_date'    => ['required', 'date'],
            'payment_id'   => ['nullable', 'integer'],
            'payment_type' => ['nullable', 'in:payment_received,payment_made'],
        ]);

        return $this->tryAction(
            fn() => $this->installmentService->recordPayment($installmentSchedule, $validated)->load('plan'),
            'Payment recorded.',
            'PAYMENT_FAILED'
        );
    }

    /** Mark overdue installments for this organisation. */
    public function markOverdue(Request $request): JsonResponse
    {
        $count = $this->installmentService->markOverdue($this->organizationId($request));

        return $this->success(['marked_overdue' => $count], "{$count} installments marked overdue.");
    }

    /** Upcoming installments due within the next N days. */
    public function upcoming(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $data = $this->installmentService->upcomingDue($this->organizationId($request), $days);

        return $this->success($data);
    }

    /** Overdue summary by plan. */
    public function overdueSummary(Request $request): JsonResponse
    {
        $data = $this->installmentService->overdueSummary($this->organizationId($request));

        return $this->success($data);
    }
}
