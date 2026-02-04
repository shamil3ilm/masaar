<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Resources\Purchase\PaymentMadeResource;
use App\Models\Purchase\Bill;
use App\Models\Purchase\PaymentMade;
use App\Services\Purchase\PaymentMadeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMadeController extends Controller
{
    public function __construct(
        private PaymentMadeService $paymentMadeService
    ) {}

    /**
     * List payments made with filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentMade::with(['supplier', 'bankAccount', 'allocations.bill'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->supplier_id, fn($q, $id) => $q->forSupplier($id))
            ->when($request->payment_method, fn($q, $method) => $q->where('payment_method', $method))
            ->when($request->start_date, fn($q, $date) => $q->where('payment_date', '>=', $date))
            ->when($request->end_date, fn($q, $date) => $q->where('payment_date', '<=', $date))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('payment_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q) use ($search) {
                            $q->where('company_name', 'like', "%{$search}%")
                                ->orWhere('contact_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($request->sort_by ?? 'payment_date', $request->sort_order ?? 'desc');

        $payments = $request->per_page
            ? $query->paginate((int) $request->per_page)
            : $query->get();

        return PaymentMadeResource::collection($payments);
    }

    /**
     * Store a new payment made.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:contacts,id',
            'payment_number' => 'nullable|string|max:50',
            'payment_date' => 'required|date',
            'branch_id' => 'nullable|exists:branches,id',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,credit_card,online,other',
            'amount' => 'required|numeric|min:0.01',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'allocations' => 'nullable|array',
            'allocations.*.bill_id' => 'required|exists:bills,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $payment = $this->paymentMadeService->create(
            collect($validated)->except('allocations')->toArray(),
            $validated['allocations'] ?? []
        );

        return response()->json([
            'message' => 'Payment created successfully.',
            'data' => new PaymentMadeResource($payment),
        ], 201);
    }

    /**
     * Show a specific payment made.
     */
    public function show(PaymentMade $paymentMade): PaymentMadeResource
    {
        return new PaymentMadeResource(
            $paymentMade->load(['supplier', 'bankAccount', 'allocations.bill', 'journalEntry.lines'])
        );
    }

    /**
     * Delete a pending payment.
     */
    public function destroy(PaymentMade $paymentMade): JsonResponse
    {
        if (!$paymentMade->isEditable()) {
            return response()->json([
                'message' => 'Only pending payments can be deleted.',
            ], 422);
        }

        $paymentMade->allocations()->delete();
        $paymentMade->delete();

        return response()->json([
            'message' => 'Payment deleted successfully.',
        ]);
    }

    /**
     * Complete/confirm a payment.
     */
    public function complete(PaymentMade $paymentMade): JsonResponse
    {
        $payment = $this->paymentMadeService->complete($paymentMade);

        return response()->json([
            'message' => 'Payment completed successfully.',
            'data' => new PaymentMadeResource($payment),
        ]);
    }

    /**
     * Void a payment.
     */
    public function void(Request $request, PaymentMade $paymentMade): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $payment = $this->paymentMadeService->void($paymentMade, $validated['reason'] ?? '');

        return response()->json([
            'message' => 'Payment voided successfully.',
            'data' => new PaymentMadeResource($payment),
        ]);
    }

    /**
     * Allocate payment to bills.
     */
    public function allocate(Request $request, PaymentMade $paymentMade): JsonResponse
    {
        $validated = $request->validate([
            'bill_id' => 'required|exists:bills,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $bill = Bill::findOrFail($validated['bill_id']);
        $allocation = $this->paymentMadeService->allocate(
            $paymentMade,
            $bill,
            (float) $validated['amount']
        );

        return response()->json([
            'message' => 'Payment allocated successfully.',
            'data' => new PaymentMadeResource($paymentMade->fresh(['allocations.bill'])),
        ]);
    }

    /**
     * Get supplier statement.
     */
    public function supplierStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:contacts,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $statement = $this->paymentMadeService->getSupplierStatement(
            (int) $validated['supplier_id'],
            isset($validated['start_date']) ? new \DateTime($validated['start_date']) : null,
            isset($validated['end_date']) ? new \DateTime($validated['end_date']) : null
        );

        return response()->json(['data' => $statement]);
    }

    /**
     * Get payments summary/stats.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = PaymentMade::query();

        if ($request->supplier_id) {
            $query->forSupplier($request->supplier_id);
        }

        $pending = (clone $query)->pending()->count();
        $completed = (clone $query)->completed()->count();

        $pendingValue = (clone $query)->pending()->sum('amount');
        $completedValue = (clone $query)->completed()->sum('amount');

        $thisMonth = (clone $query)->completed()
            ->whereBetween('payment_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        return response()->json([
            'data' => [
                'total_count' => $query->count(),
                'pending_count' => $pending,
                'completed_count' => $completed,
                'pending_value' => (float) $pendingValue,
                'completed_value' => (float) $completedValue,
                'this_month_value' => (float) $thisMonth,
            ],
        ]);
    }
}
