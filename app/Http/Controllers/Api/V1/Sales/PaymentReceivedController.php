<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\PaymentReceivedResource;
use App\Models\Sales\Invoice;
use App\Models\Sales\PaymentReceived;
use App\Services\Sales\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentReceivedController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * List payments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentReceived::with(['customer', 'bankAccount'])
            ->latest('payment_date');

        $query
            ->when($request->customer_id, fn ($q, $v) => $q->forCustomer((int) $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->payment_method, fn ($q, $v) => $q->byMethod($v))
            ->when(
                $request->input('from_date', $request->input('start_date')),
                fn ($q, $v) => $q->where('payment_date', '>=', $v)
            )
            ->when(
                $request->input('to_date', $request->input('end_date')),
                fn ($q, $v) => $q->where('payment_date', '<=', $v)
            );

        $payments = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($payments, PaymentReceivedResource::class);
    }

    /**
     * Create a new payment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('organization_id', auth()->user()->organization_id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('organization_id', auth()->user()->organization_id)],
            'payment_date' => 'required|date',
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')->where('organization_id', auth()->user()->organization_id)],
            'payment_method' => 'required|in:cash,bank_transfer,cheque,credit_card,online,other',
            'amount' => 'required|numeric|gt:0',
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('organization_id', auth()->user()->organization_id)],
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        // Validate allocation amounts don't exceed invoice due amounts
        if (!empty($validated['allocations'])) {
            $invoiceIds = array_column($validated['allocations'], 'invoice_id');
            $invoices = Invoice::whereIn('id', $invoiceIds)->get()->keyBy('id');

            foreach ($validated['allocations'] as $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                if ($invoice && $allocation['amount'] > (float) $invoice->amount_due) {
                    return $this->error(
                        "Allocation amount ({$allocation['amount']}) exceeds invoice amount due ({$invoice->amount_due}).",
                        'VALIDATION_ERROR',
                        422
                    );
                }
            }
        }

        $payment = $this->paymentService->create(
            collect($validated)->except('allocations')->toArray(),
            $validated['allocations'] ?? []
        );

        return $this->created(new PaymentReceivedResource($payment), 'Payment created successfully.');
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

        return $this->success(new PaymentReceivedResource($paymentReceived));
    }

    /**
     * Complete a payment.
     */
    public function complete(PaymentReceived $paymentReceived): JsonResponse
    {
        return $this->tryAction(
            fn() => new PaymentReceivedResource($this->paymentService->complete($paymentReceived)),
            'Payment completed successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Void a payment.
     */
    public function void(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->tryAction(
            fn() => new PaymentReceivedResource($this->paymentService->void($paymentReceived, $request->input('reason', ''))),
            'Payment voided successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Record a bounced cheque.
     */
    public function bounce(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->tryAction(
            fn() => new PaymentReceivedResource($this->paymentService->recordBounce($paymentReceived, $request->input('reason', ''))),
            'Cheque bounce recorded.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Allocate payment to invoices.
     */
    public function allocate(Request $request, PaymentReceived $paymentReceived): JsonResponse
    {
        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('organization_id', auth()->user()->organization_id)],
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        return $this->tryAction(
            function () use ($validated, $paymentReceived) {
                $results = [];
                foreach ($validated['allocations'] as $allocation) {
                    $invoice = \App\Models\Sales\Invoice::findOrFail($allocation['invoice_id']);
                    $results[] = $this->paymentService->allocate($paymentReceived, $invoice, $allocation['amount']);
                }
                return [
                    'allocations'        => $results,
                    'unallocated_amount' => $paymentReceived->fresh()->getUnallocatedAmount(),
                ];
            },
            'Payment allocated successfully.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * List open (unpaid / partially paid) invoices for a customer.
     */
    public function openItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('contacts', 'id')
                    ->where('organization_id', auth()->user()->organization_id),
            ],
        ]);

        $invoices = Invoice::where('customer_id', $validated['customer_id'])
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIAL,
                Invoice::STATUS_OVERDUE,
            ])
            ->orderBy('invoice_date')
            ->get(['id', 'uuid', 'invoice_number', 'invoice_date', 'due_date', 'total', 'amount_paid', 'amount_due', 'status', 'currency_code']);

        return $this->success($invoices);
    }

    /**
     * Clear open items: apply unallocated payments to selected invoices.
     */
    public function clearOpenItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('contacts', 'id')
                    ->where('organization_id', auth()->user()->organization_id),
            ],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')
                    ->where('organization_id', auth()->user()->organization_id),
            ],
            'clearing_date' => ['nullable', 'date'],
        ]);

        $customer = \App\Models\Sales\Contact::findOrFail($validated['customer_id']);
        $clearingDate = $validated['clearing_date'] ?? now()->toDateString();

        $result = $this->paymentService->clearOpenItems(
            $customer,
            $validated['invoice_ids'],
            $clearingDate
        );

        return $this->success($result, 'Open items cleared successfully.');
    }

    /**
     * Delete a pending payment.
     */
    public function destroy(PaymentReceived $paymentReceived): JsonResponse
    {
        if ($paymentReceived->status !== PaymentReceived::STATUS_PENDING) {
            return $this->error('Only pending payments can be deleted.', 'VALIDATION_ERROR', 422);
        }

        // Remove allocations first
        foreach ($paymentReceived->allocations as $allocation) {
            $this->paymentService->deallocate($allocation);
        }

        $paymentReceived->delete();

        return $this->success(null, 'Payment deleted successfully.');
    }

    /**
     * Get payment summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $query = PaymentReceived::completed()
            ->when(
                $request->has('from_date') || $request->has('start_date'),
                fn($q) => $q->where('payment_date', '>=', $request->input('from_date', $request->input('start_date')))
            )
            ->when(
                $request->has('to_date') || $request->has('end_date'),
                fn($q) => $q->where('payment_date', '<=', $request->input('to_date', $request->input('end_date')))
            );

        $stats = [
            'total_payments' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'by_method' => PaymentReceived::completed()
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get()
                ->keyBy('payment_method'),
        ];

        return $this->success($stats);
    }
}
