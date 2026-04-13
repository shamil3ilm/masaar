<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trade;

use App\Http\Controllers\Controller;
use App\Models\Trade\LetterOfCredit;
use App\Services\Trade\LetterOfCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LetterOfCreditController extends Controller
{
    public function __construct(
        private LetterOfCreditService $lcService
    ) {
    }

    /**
     * List letters of credit.
     */
    public function index(Request $request): JsonResponse
    {
        $query = LetterOfCredit::with(['applicant:id,name', 'beneficiary:id,name', 'createdBy:id,name'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->when($request->has('status'), fn($q) => $q->forStatus($request->input('status')))
            ->when($request->has('lc_type'), fn($q) => $q->forType($request->input('lc_type')))
            ->when($request->has('currency_code'), fn($q) => $q->forCurrency($request->input('currency_code')))
            ->when($request->boolean('expiring_soon'), fn($q) => $q->expiringSoon($request->integer('expiring_days', 30)))
            ->when($request->has('search'), fn($q) => $q->where('lc_number', 'like', "%{$request->input('search')}%"));

        $lcs = $query->paginate($request->integer('per_page', 20));

        return $this->paginated($lcs);
    }

    /**
     * Create a letter of credit.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lc_number' => ['nullable', 'string', 'max:50'],
            'lc_type' => ['required', 'in:import,export,standby,revolving,transferable,back_to_back'],
            'is_irrevocable' => ['sometimes', 'boolean'],
            'is_confirmed' => ['sometimes', 'boolean'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'issuing_bank' => ['sometimes', 'string', 'max:255'],
            'issuing_bank_swift' => ['nullable', 'string', 'max:11'],
            'advising_bank' => ['nullable', 'string', 'max:255'],
            'advising_bank_swift' => ['nullable', 'string', 'max:11'],
            'confirming_bank' => ['nullable', 'string', 'max:255'],
            'negotiating_bank' => ['nullable', 'string', 'max:255'],
            'applicant_id' => ['nullable', 'exists:contacts,id'],
            'beneficiary_id' => ['nullable', 'exists:contacts,id'],
            'currency_code' => ['required', 'string', 'max:3'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tolerance_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['required', 'date'],
            'latest_shipment_date' => ['nullable', 'date'],
            'place_of_expiry' => ['nullable', 'string', 'max:255'],
            'presentation_days' => ['sometimes', 'integer', 'min:1'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'port_of_discharge' => ['nullable', 'string', 'max:255'],
            'partial_shipments_allowed' => ['sometimes', 'boolean'],
            'transhipment_allowed' => ['sometimes', 'boolean'],
            'required_documents' => ['nullable', 'array'],
            'terms_and_conditions' => ['nullable', 'string'],
            'special_conditions' => ['nullable', 'string'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $validated['organization_id'] = $this->organizationId($request);

        try {
            $lc = $this->lcService->create($validated);
            return $this->created($lc);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 'VALIDATION_ERROR', 422);
        }
    }

    /**
     * Show a letter of credit.
     */
    public function show(LetterOfCredit $letterOfCredit): JsonResponse
    {
        $letterOfCredit->load([
            'applicant',
            'beneficiary',
            'bankAccount',
            'amendments',
            'shipments',
            'purchaseOrder:id,po_number',
            'journalEntry',
            'createdBy:id,name',
        ]);

        return $this->success($letterOfCredit);
    }

    /**
     * Update a letter of credit.
     */
    public function update(Request $request, LetterOfCredit $letterOfCredit): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'issuing_bank' => ['sometimes', 'string', 'max:255'],
            'issuing_bank_swift' => ['nullable', 'string', 'max:11'],
            'advising_bank' => ['nullable', 'string', 'max:255'],
            'advising_bank_swift' => ['nullable', 'string', 'max:11'],
            'confirming_bank' => ['nullable', 'string', 'max:255'],
            'negotiating_bank' => ['nullable', 'string', 'max:255'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'expiry_date' => ['nullable', 'date'],
            'latest_shipment_date' => ['nullable', 'date'],
            'place_of_expiry' => ['nullable', 'string', 'max:255'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'port_of_discharge' => ['nullable', 'string', 'max:255'],
            'partial_shipments_allowed' => ['sometimes', 'boolean'],
            'transhipment_allowed' => ['sometimes', 'boolean'],
            'required_documents' => ['nullable', 'array'],
            'terms_and_conditions' => ['nullable', 'string'],
            'special_conditions' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->tryAction(
            fn() => $this->lcService->update($letterOfCredit, $validated),
            'Letter of credit updated successfully',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Delete a letter of credit.
     */
    public function destroy(LetterOfCredit $letterOfCredit): JsonResponse
    {
        if (!$letterOfCredit->isEditable()) {
            return $this->error('Only draft or applied LCs can be deleted.', 'INVALID_STATUS', 400);
        }

        $letterOfCredit->amendments()->delete();
        $letterOfCredit->delete();

        return $this->success(null, 'Letter of credit deleted successfully');
    }

    /**
     * Issue a letter of credit.
     */
    public function issue(Request $request, LetterOfCredit $letterOfCredit): JsonResponse
    {
        $validated = $request->validate([
            'issue_date' => ['nullable', 'date'],
        ]);

        return $this->tryAction(
            fn() => $this->lcService->issue($letterOfCredit, $validated['issue_date'] ?? null),
            'Letter of credit issued successfully',
            'ISSUE_FAILED'
        );
    }

    /**
     * Amend a letter of credit.
     */
    public function amend(Request $request, LetterOfCredit $letterOfCredit): JsonResponse
    {
        $validated = $request->validate([
            'amendment_date' => ['sometimes', 'date'],
            'amendment_details' => ['sometimes', 'string', 'max:1000'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'new_amount' => ['nullable', 'numeric', 'min:0.01'],
            'new_expiry_date' => ['nullable', 'date'],
            'new_latest_shipment_date' => ['nullable', 'date'],
            'new_tolerance_percent' => ['nullable', 'numeric', 'min:0'],
            'changes' => ['sometimes', 'array'],
            'changes.amount' => ['nullable', 'numeric', 'min:0.01'],
            'changes.expiry_date' => ['nullable', 'date'],
            'changes.latest_shipment_date' => ['nullable', 'date'],
            'changes.tolerance_percent' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Normalize: accept both flat new_* fields and nested changes array
        $description = $validated['description'] ?? $validated['amendment_details'] ?? '';
        $changes = $validated['changes'] ?? [];

        if (isset($validated['new_amount'])) {
            $changes['amount'] = $validated['new_amount'];
        }
        if (isset($validated['new_expiry_date'])) {
            $changes['expiry_date'] = $validated['new_expiry_date'];
        }
        if (isset($validated['new_latest_shipment_date'])) {
            $changes['latest_shipment_date'] = $validated['new_latest_shipment_date'];
        }
        if (isset($validated['new_tolerance_percent'])) {
            $changes['tolerance_percent'] = $validated['new_tolerance_percent'];
        }

        $amendmentData = [
            'amendment_date' => $validated['amendment_date'] ?? null,
            'description' => $description,
            'changes' => $changes,
        ];

        return $this->tryAction(
            fn() => $this->lcService->amend($letterOfCredit, $amendmentData),
            'Letter of credit amended successfully',
            'AMEND_FAILED'
        );
    }

    /**
     * Utilize (draw down) from a letter of credit.
     */
    public function utilize(Request $request, LetterOfCredit $letterOfCredit): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->tryAction(
            fn() => $this->lcService->utilize($letterOfCredit, (float) $validated['amount'], $validated['reference'] ?? null),
            'LC utilization recorded successfully',
            'UTILIZE_FAILED'
        );
    }

    /**
     * Close a letter of credit.
     */
    public function close(Request $request, LetterOfCredit $letterOfCredit): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->tryAction(
            fn() => $this->lcService->close($letterOfCredit),
            'Letter of credit closed successfully',
            'CLOSE_FAILED'
        );
    }
}
