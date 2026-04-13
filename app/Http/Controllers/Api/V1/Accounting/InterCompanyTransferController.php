<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\InterCompanyTransfer;
use App\Services\Accounting\InterCompanyTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterCompanyTransferController extends Controller
{
    public function __construct(
        private InterCompanyTransferService $transferService
    ) {}

    /**
     * List inter-company transfers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InterCompanyTransfer::with([
                'fromBranch:id,name',
                'toBranch:id,name',
                'createdBy:id,name',
            ])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        $query
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->transfer_type, fn ($q, $v) => $q->where('transfer_type', $v))
            ->when($request->from_branch_id, fn ($q, $v) => $q->where('from_branch_id', $v))
            ->when($request->to_branch_id, fn ($q, $v) => $q->where('to_branch_id', $v))
            ->when($request->start_date, fn ($q, $v) => $q->whereDate('transfer_date', '>=', $v))
            ->when($request->end_date, fn ($q, $v) => $q->whereDate('transfer_date', '<=', $v))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('transfer_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('purpose', 'like', "%{$search}%");
                });
            });

        $transfers = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($transfers);
    }

    /**
     * Create a new inter-company transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_type' => ['required', 'string', 'in:fund_transfer,loan,investment'],
            'from_branch_id' => ['nullable', 'exists:branches,id'],
            'from_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'to_branch_id' => ['nullable', 'exists:branches,id'],
            'to_bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'to_organization_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'transfer_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string'],
            'loan_id' => ['nullable', 'exists:loans,id'],
        ]);

        try {
            $transfer = $this->transferService->create([
                ...$validated,
                'organization_id' => $this->organizationId($request),
            ], auth()->id());

            return $this->created($transfer, 'Inter-company transfer created successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a single inter-company transfer.
     */
    public function show(InterCompanyTransfer $interCompanyTransfer): JsonResponse
    {
        $interCompanyTransfer->load([
            'fromBranch:id,name',
            'toBranch:id,name',
            'fromBankAccount:id,account_name,bank_name',
            'toBankAccount:id,account_name,bank_name',
            'journalEntry',
            'loan:id,loan_number',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return $this->success($interCompanyTransfer);
    }

    /**
     * Approve or reject a pending transfer.
     */
    public function review(Request $request, InterCompanyTransfer $interCompanyTransfer): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->tryAction(
            function () use ($validated, $interCompanyTransfer) {
                return $validated['action'] === 'approve'
                    ? $this->transferService->approve($interCompanyTransfer, auth()->id())
                    : $this->transferService->reject($interCompanyTransfer, $validated['reason'] ?? null);
            },
            $validated['action'] === 'approve' ? 'Transfer approved successfully' : 'Transfer cancelled successfully',
            'VALIDATION_ERROR',
            400
        );
    }

    /**
     * Complete an approved transfer.
     */
    public function complete(InterCompanyTransfer $interCompanyTransfer): JsonResponse
    {
        return $this->tryAction(
            fn() => $this->transferService->complete($interCompanyTransfer),
            'Transfer completed successfully',
            'COMPLETE_FAILED',
            400
        );
    }
}
