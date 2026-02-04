<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\PaymentReceivedResource;
use App\Models\Sales\PaymentReceived;
use App\Services\Sales\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentReceivedController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * List payments.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PaymentReceived::with(['customer', 'bankAccount'])
            ->latest('payment_date');

        if ($request->has('customer_id')) {
            $query->forCustomer($request->integer('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('payment_method')) {
            $query->byMethod($request->input('payment_method'));
        }

        if ($request->has('from_date')) {
            $query->where('payment_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('payment_date', '<=', $request->input('to_date'));
        }

        $payments = $query->paginate($request->integer('per_page', 15));

        return PaymentReceivedResource::collection($payments);
    }

    /**
     * Create a new payment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:contacts,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'payment_date' => 'required|date',
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,credit_card,online,other',
            'amount' => 'required|numeric|gt:0',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => 'required|integer|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        $payment = $this->paymentService->create(
            collect($validated)->except('allocations')->toArray(),
            $validated['allocations'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully.',
            'data' => new PaymentReceivedResource($payment),
        ], 201);
    }

    /**
     * Show a payment.
     */
    public function show(PaymentReceived $paymentReceived): JsonResponse
    {
        $paymentReceived->load([
            'customer',
            'bankAccount',
            'allocations.invoice',
            'journalEntry.lines',
        ]);

        return response()->json([
            'success' => true,
            'data' => new PaymentReceivedResource($paymentReceived),
        ]);
    }

    /**
     * Complete a payment.
     */
    public function complete(PaymentReceived $paymentReceived): JsonResponse
    {
        $payment = $this->paymentService->complete($paymentReceived);

        return response()->json([
            'success' => true,
            'message' => 'Payment completed successfully.',
            'data' => new PaymentReceivedResource($payment),
        ]);
    }

    /**
     * Void a payment.
     */
    public function void(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->paymentService->void($paymentReceived, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Payment voided successfully.',
            'data' => new PaymentReceivedResource($payment),
        ]);
    }

    /**
     * Record a bounced cheque.
     */
    public function bounce(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->paymentService->recordBounce($paymentReceived, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Cheque bounce recorded.',
            'data' => new PaymentReceivedResource($payment),
        ]);
    }

    /**
     * Allocate payment to invoices.
     */
    public function allocate(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|integer|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        $results = [];
        foreach ($validated['allocations'] as $allocation) {
            $invoice = \App\Models\Sales\Invoice::findOrFail($allocation['invoice_id']);
            $alloc = $this->paymentService->allocate($paymentReceived, $invoice, $allocation['amount']);
            $results[] = $alloc;
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment allocated successfully.',
            'data' => [
                'allocations' => $results,
                'unallocated_amount' => $paymentReceived->fresh()->getUnallocatedAmount(),
            ],
        ]);
    }

    /**
     * Delete a pending payment.
     */
    public function destroy(PaymentReceived $paymentReceived): JsonResponse
    {
        if ($paymentReceived->status !== PaymentReceived::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Only pending payments can be deleted.',
                ],
            ], 422);
        }

        // Remove allocations first
        foreach ($paymentReceived->allocations as $allocation) {
            $this->paymentService->deallocate($allocation);
        }

        $paymentReceived->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment deleted successfully.',
        ]);
    }

    /**
     * Get payment summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = PaymentReceived::completed();

        if ($request->has('from_date')) {
            $query->where('payment_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('payment_date', '<=', $request->input('to_date'));
        }

        $stats = [
            'total_payments' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'by_method' => PaymentReceived::completed()
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get()
                ->keyBy('payment_method'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
