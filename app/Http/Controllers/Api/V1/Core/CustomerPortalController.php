<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\PortalUser;
use App\Models\Sales\Invoice;
use App\Models\Sales\Quotation;
use App\Models\Sales\SalesOrder;
use App\Services\Core\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly CustomerPortalService $portalService
    ) {}

    // -------------------------------------------------------------------------
    // Public auth endpoints
    // -------------------------------------------------------------------------

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'email'      => 'required|email|max:150',
            'password'   => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = $this->portalService->register(
                $validated['contact_id'],
                $validated['email'],
                $validated['password']
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'REGISTRATION_FAILED', 422);
        }

        return $this->created([
            'uuid'  => $user->uuid,
            'email' => $user->email,
        ], 'Portal account created successfully.');
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'email'           => 'required|email',
            'password'        => 'required|string',
        ]);

        try {
            $result = $this->portalService->login(
                $validated['organization_id'],
                $validated['email'],
                $validated['password']
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'LOGIN_FAILED', 401);
        }

        return $this->success([
            'token'      => $result['token'],
            'expires_at' => $result['expires_at'],
            'user'       => [
                'uuid'  => $result['portal_user']->uuid,
                'email' => $result['portal_user']->email,
            ],
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->input('token', '');
        $this->portalService->logout($token);

        return $this->success(null, 'Logged out successfully.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'           => 'required|email',
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $this->portalService->requestPasswordReset(
            $validated['email'],
            $validated['organization_id']
        );

        return $this->success(null, 'If an account exists, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->portalService->resetPassword($validated['token'], $validated['password']);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'RESET_FAILED', 422);
        }

        return $this->success(null, 'Password has been reset.');
    }

    // -------------------------------------------------------------------------
    // Authenticated portal endpoints
    // -------------------------------------------------------------------------

    public function profile(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized('Portal session not found or expired.');
        }

        return $this->success([
            'uuid'            => $portalUser->uuid,
            'email'           => $portalUser->email,
            'contact'         => $portalUser->contact()->first(),
            'last_login_at'   => $portalUser->last_login_at,
            'login_count'     => $portalUser->login_count,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $invoices = $this->portalService->getCustomerInvoices($portalUser->id);

        return $this->success($invoices);
    }

    public function showInvoice(Request $request, int $id): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $invoice = Invoice::where('customer_id', $portalUser->contact_id)
            ->with('lines')
            ->find($id);

        if (! $invoice) {
            return $this->notFound('Invoice not found.');
        }

        $this->portalService->logActivity(
            $portalUser->id,
            'invoice_viewed',
            "Viewed invoice #{$invoice->invoice_number}"
        );

        return $this->success($invoice);
    }

    public function orders(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $orders = $this->portalService->getCustomerOrders($portalUser->id);

        return $this->success($orders);
    }

    public function quotations(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $quotations = $this->portalService->getCustomerQuotations($portalUser->id);

        return $this->success($quotations);
    }

    public function statement(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $statement = $this->portalService->getCustomerStatement($portalUser->id);

        return $this->success($statement);
    }

    // -------------------------------------------------------------------------
    // Extended portal endpoints (Gap 4 additions)
    // -------------------------------------------------------------------------

    /**
     * Dashboard summary: open invoice count/total, outstanding balance, recent orders.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $summary = $this->portalService->getDashboard($portalUser->id);

        return $this->success($summary);
    }

    /**
     * Paginated invoices with optional status/date filters.
     */
    public function invoicesPaginated(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $paginated = $this->portalService->getCustomerInvoicesPaginated(
            $portalUser->id,
            $request->only(['status', 'start_date', 'end_date', 'per_page'])
        );

        return $this->paginated($paginated);
    }

    /**
     * Detail for a single invoice (verifies customer ownership).
     */
    public function invoiceDetail(Request $request, Invoice $invoice): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        if ($invoice->customer_id !== $portalUser->contact_id) {
            return $this->forbidden('You do not have access to this invoice.');
        }

        $invoice = $this->portalService->getCustomerInvoice($portalUser->id, $invoice->id);

        if (! $invoice) {
            return $this->notFound('Invoice not found.');
        }

        return $this->success($invoice->load('lines'));
    }

    /**
     * Paginated sales orders with optional status filter.
     */
    public function ordersPaginated(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $paginated = $this->portalService->getCustomerOrdersPaginated(
            $portalUser->id,
            $request->only(['status', 'per_page'])
        );

        return $this->paginated($paginated);
    }

    /**
     * Detail for a single sales order (verifies customer ownership).
     */
    public function orderDetail(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        if ($salesOrder->customer_id !== $portalUser->contact_id) {
            return $this->forbidden('You do not have access to this order.');
        }

        return $this->success($salesOrder->load('lines'));
    }

    /**
     * Paginated quotations.
     */
    public function quotationsPaginated(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $paginated = $this->portalService->getCustomerQuotationsPaginated(
            $portalUser->id,
            $request->only(['status', 'per_page'])
        );

        return $this->paginated($paginated);
    }

    /**
     * Customer accepts a quotation.
     */
    public function acceptQuotation(Request $request, Quotation $quotation): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        return $this->tryAction(
            fn() => $this->portalService->acceptQuotation($portalUser->id, $quotation),
            'Quotation accepted.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Customer declines a quotation.
     */
    public function declineQuotation(Request $request, Quotation $quotation): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        return $this->tryAction(
            fn() => $this->portalService->declineQuotation($portalUser->id, $quotation),
            'Quotation declined.',
            'VALIDATION_ERROR'
        );
    }

    /**
     * Paginated payment history.
     */
    public function payments(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $paginated = $this->portalService->getCustomerPayments(
            $portalUser->id,
            $request->only(['start_date', 'end_date', 'per_page'])
        );

        return $this->paginated($paginated);
    }

    /**
     * Total outstanding balance across all open invoices.
     */
    public function outstandingBalance(Request $request): JsonResponse
    {
        $portalUser = $this->resolvePortalUser($request);

        if (! $portalUser) {
            return $this->unauthorized();
        }

        $balance = $this->portalService->getOutstandingBalance($portalUser->id);

        return $this->success(['outstanding_balance' => $balance]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the portal user from a bearer token (not the JWT user).
     * Falls back to looking up by bearer token in portal_sessions.
     */
    private function resolvePortalUser(Request $request): ?PortalUser
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        return $this->portalService->getUserFromToken($token);
    }
}
