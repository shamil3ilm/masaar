<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\BackdatedTransaction;
use App\Services\Sales\BackdatedTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackdatedTransactionController extends Controller
{
    public function __construct(
        private BackdatedTransactionService $backdatedService
    ) {}

    /**
     * List backdated transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BackdatedTransaction::with(['approver', 'creator'])
            ->latest()
            ->when($request->has('transaction_type'), fn($q) => $q->byTransactionType($request->input('transaction_type')))
            ->when($request->has('status'), fn($q) => $request->input('status') === 'pending' ? $q->pending() : $q->approved())
            ->when($request->has('from_date'), fn($q) => $q->where('transaction_date', '>=', $request->input('from_date')))
            ->when($request->has('to_date'), fn($q) => $q->where('transaction_date', '<=', $request->input('to_date')))
            ->when($request->has('created_by'), fn($q) => $q->createdBy($request->integer('created_by')));

        $transactions = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($transactions);
    }

    /**
     * Create a backdated transaction log.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_type' => 'required|string|max:255',
            'transaction_id' => 'required|integer',
            'transaction_date' => 'required|date|before_or_equal:today',
            'entry_date' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $transaction = $this->backdatedService->create($validated, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }

        return $this->created($transaction, 'Backdated transaction logged successfully.');
    }

    /**
     * Show a backdated transaction.
     */
    public function show(BackdatedTransaction $backdatedTransaction): JsonResponse
    {
        $backdatedTransaction->load(['transaction', 'approver', 'creator']);

        return $this->success($backdatedTransaction);
    }

    /**
     * Approve a backdated transaction.
     */
    public function approve(BackdatedTransaction $backdatedTransaction): JsonResponse
    {
        try {
            $transaction = $this->backdatedService->approve($backdatedTransaction, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }

        return $this->success($transaction, 'Backdated transaction approved successfully.');
    }

    /**
     * Reject a backdated transaction.
     */
    public function reject(Request $request, BackdatedTransaction $backdatedTransaction): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->backdatedService->reject(
                $backdatedTransaction,
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            report($e);
            return $this->error('An unexpected error occurred. Please try again.', 'SERVER_ERROR', 500);
        }

        return $this->success(null, 'Backdated transaction rejected successfully.');
    }

    /**
     * Validate a date for backdating.
     */
    public function validateDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_date' => 'required|date',
        ]);

        return $this->tryAction(
            function () use ($validated) {
                $this->backdatedService->validateDate($validated['transaction_date']);
                return ['valid' => true, 'transaction_date' => $validated['transaction_date']];
            },
            'Date is valid for backdating.',
            'VALIDATION_ERROR'
        );
    }
}
