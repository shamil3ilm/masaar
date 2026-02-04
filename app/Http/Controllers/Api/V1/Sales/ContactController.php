<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sales\ContactResource;
use App\Models\Sales\Contact;
use App\Services\Sales\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /**
     * List contacts with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Contact::query();

        if ($request->has('type')) {
            match ($request->input('type')) {
                'customer' => $query->customers(),
                'supplier' => $query->suppliers(),
                default => null,
            };
        }

        if ($request->has('search')) {
            $query->search($request->input('search'));
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $contacts = $query->latest()->paginate($request->integer('per_page', 15));

        return ContactResource::collection($contacts);
    }

    /**
     * Create a new contact.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_type' => 'required|in:customer,supplier,both',
            'company_name' => 'nullable|string|max:200',
            'contact_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'tax_number' => 'nullable|string|max:50',
            'tax_registration_name' => 'nullable|string|max:200',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'receivable_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'payable_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'billing_address_line_1' => 'nullable|string|max:255',
            'billing_address_line_2' => 'nullable|string|max:255',
            'billing_city' => 'nullable|string|max:100',
            'billing_state' => 'nullable|string|max:100',
            'billing_postal_code' => 'nullable|string|max:20',
            'billing_country_code' => 'nullable|string|size:2',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'shipping_city' => 'nullable|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'shipping_country_code' => 'nullable|string|size:2',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $contact = Contact::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contact created successfully.',
            'data' => new ContactResource($contact),
        ], 201);
    }

    /**
     * Show a contact.
     */
    public function show(Contact $contact): JsonResponse
    {
        $contact->load(['receivableAccount', 'payableAccount']);

        return response()->json([
            'success' => true,
            'data' => new ContactResource($contact),
        ]);
    }

    /**
     * Update a contact.
     */
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'contact_type' => 'sometimes|in:customer,supplier,both',
            'company_name' => 'nullable|string|max:200',
            'contact_name' => 'sometimes|required|string|max:100',
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'tax_number' => 'nullable|string|max:50',
            'tax_registration_name' => 'nullable|string|max:200',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'credit_limit' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'receivable_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'payable_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            'billing_address_line_1' => 'nullable|string|max:255',
            'billing_address_line_2' => 'nullable|string|max:255',
            'billing_city' => 'nullable|string|max:100',
            'billing_state' => 'nullable|string|max:100',
            'billing_postal_code' => 'nullable|string|max:20',
            'billing_country_code' => 'nullable|string|size:2',
            'shipping_address_line_1' => 'nullable|string|max:255',
            'shipping_address_line_2' => 'nullable|string|max:255',
            'shipping_city' => 'nullable|string|max:100',
            'shipping_state' => 'nullable|string|max:100',
            'shipping_postal_code' => 'nullable|string|max:20',
            'shipping_country_code' => 'nullable|string|size:2',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
        ]);

        $contact->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contact updated successfully.',
            'data' => new ContactResource($contact->fresh()),
        ]);
    }

    /**
     * Delete a contact.
     */
    public function destroy(Contact $contact): JsonResponse
    {
        // Check for related records
        if ($contact->invoices()->count() > 0) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cannot delete contact with existing invoices.',
                ],
            ], 422);
        }

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully.',
        ]);
    }

    /**
     * Get contact statement.
     */
    public function statement(Request $request, Contact $contact): JsonResponse
    {
        if (!$contact->isCustomer()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Statement only available for customers.',
                ],
            ], 422);
        }

        $statement = $this->paymentService->getCustomerStatement(
            $contact->id,
            $request->date('start_date'),
            $request->date('end_date')
        );

        return response()->json([
            'success' => true,
            'data' => $statement,
        ]);
    }

    /**
     * Get contact balance summary.
     */
    public function balance(Contact $contact): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'outstanding_balance' => $contact->getOutstandingBalance(),
                'credit_limit' => $contact->credit_limit,
                'available_credit' => $contact->getAvailableCredit(),
                'is_over_limit' => $contact->isOverCreditLimit(),
            ],
        ]);
    }
}
