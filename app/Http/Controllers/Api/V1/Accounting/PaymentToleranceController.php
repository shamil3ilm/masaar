<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\PaymentToleranceGroup;
use App\Models\Accounting\PaymentToleranceItem;
use App\Services\Accounting\PaymentToleranceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentToleranceController extends Controller
{
    public function __construct(
        private readonly PaymentToleranceService $toleranceService,
    ) {}

    // =========================================================================
    // Tolerance Group CRUD
    // =========================================================================

    public function indexGroups(Request $request): JsonResponse
    {
        $groups = $this->toleranceService->listGroups($this->organizationId($request), $request->all());

        return $this->paginated($groups);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:20'],
            'name'        => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'applies_to'  => ['required', 'in:customer,supplier,both'],
            'is_active'   => ['sometimes', 'boolean'],
            'is_default'  => ['sometimes', 'boolean'],
            'items'       => ['sometimes', 'array'],
            'items.*.currency_code'          => ['required', 'string', 'size:3'],
            'items.*.underpay_abs'           => ['sometimes', 'numeric', 'min:0'],
            'items.*.underpay_pct'           => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'items.*.overpay_abs'            => ['sometimes', 'numeric', 'min:0'],
            'items.*.overpay_pct'            => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'items.*.underpay_gl_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'items.*.overpay_gl_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        try {
            $group = $this->toleranceService->createGroup($validated, $this->organizationId($request));

            return $this->created($group);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'DUPLICATE_CODE', 422);
        }
    }

    public function showGroup(PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        $paymentToleranceGroup->load(['items.underpayGlAccount:id,code,name', 'items.overpayGlAccount:id,code,name']);

        return $this->success($paymentToleranceGroup);
    }

    public function updateGroup(Request $request, PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['sometimes', 'string', 'max:20'],
            'name'        => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'applies_to'  => ['sometimes', 'in:customer,supplier,both'],
            'is_active'   => ['sometimes', 'boolean'],
            'is_default'  => ['sometimes', 'boolean'],
        ]);

        return $this->tryAction(
            fn() => $this->toleranceService->updateGroup($paymentToleranceGroup, $validated),
            'Tolerance group updated.',
            'UPDATE_FAILED'
        );
    }

    public function destroyGroup(PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        return $this->tryAction(
            function () use ($paymentToleranceGroup) {
                $this->toleranceService->deleteGroup($paymentToleranceGroup);
                return null;
            },
            'Tolerance group deleted.',
            'DELETE_FAILED'
        );
    }

    // =========================================================================
    // Tolerance Items (currency thresholds)
    // =========================================================================

    public function upsertItem(Request $request, PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        $validated = $request->validate([
            'currency_code'          => ['required', 'string', 'size:3'],
            'underpay_abs'           => ['sometimes', 'numeric', 'min:0'],
            'underpay_pct'           => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'overpay_abs'            => ['sometimes', 'numeric', 'min:0'],
            'overpay_pct'            => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'underpay_gl_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'overpay_gl_account_id'  => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
        ]);

        $item = $this->toleranceService->upsertItem($paymentToleranceGroup, $validated);

        return $this->success($item);
    }

    public function removeItem(PaymentToleranceGroup $paymentToleranceGroup, PaymentToleranceItem $paymentToleranceItem): JsonResponse
    {
        $this->toleranceService->removeItem($paymentToleranceItem);

        return $this->success(null, 'Tolerance item removed.');
    }

    // =========================================================================
    // Evaluate (preview — no side effects)
    // =========================================================================

    public function evaluate(Request $request, PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        $validated = $request->validate([
            'invoice_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
            'currency_code'  => ['required', 'string', 'size:3'],
        ]);

        $result = $this->toleranceService->evaluate(
            $paymentToleranceGroup,
            (float) $validated['invoice_amount'],
            (float) $validated['payment_amount'],
            strtoupper($validated['currency_code'])
        );

        // Don't expose the Eloquent object directly
        $result['tolerance_item'] = $result['tolerance_item']
            ? $result['tolerance_item']->only(['currency_code', 'underpay_abs', 'underpay_pct', 'overpay_abs', 'overpay_pct'])
            : null;

        return $this->success($result);
    }

    // =========================================================================
    // Clear (post tolerance difference to GL)
    // =========================================================================

    public function clearDifference(Request $request, PaymentToleranceGroup $paymentToleranceGroup): JsonResponse
    {
        $validated = $request->validate([
            'invoice_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
            'currency_code'  => ['required', 'string', 'size:3'],
            'payment_type'   => ['required', 'in:payment_received,payment_made'],
            'payment_id'     => ['required', 'integer'],
            'contact_id'     => ['nullable', 'integer'],
            'document_type'  => ['nullable', 'string', 'max:50'],
            'document_id'    => ['nullable', 'integer'],
            'posting_date'   => ['required', 'date'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $post = $this->toleranceService->clearDifference(
                $paymentToleranceGroup,
                (float) $validated['invoice_amount'],
                (float) $validated['payment_amount'],
                array_merge($validated, [
                    'organization_id' => $this->organizationId($request),
                    'created_by'      => $request->user()->id,
                ]),
                [
                    'entry_date'  => $validated['posting_date'],
                    'branch_id'   => $request->header('X-Branch-Id'),
                    'source_type' => 'payment_tolerance',
                    'source_id'   => null,
                ]
            );

            return $this->created($post->load('toleranceGroup:id,code,name'));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'TOLERANCE_EXCEEDED', 422);
        }
    }

    // =========================================================================
    // Reporting
    // =========================================================================

    public function varianceSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->toleranceService->varianceSummary(
            $this->organizationId($request),
            $validated['from'],
            $validated['to'],
        );

        return $this->success($data);
    }

    public function indexDifferencePosts(Request $request): JsonResponse
    {
        $posts = $this->toleranceService->listDifferencePosts(
            $this->organizationId($request),
            $request->all()
        );

        return $this->paginated($posts);
    }
}
