<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LicenseRegistrationRequest;
use App\Models\LicenseRegistration;
use App\Services\LicenseRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * License Registration Controller.
 *
 * Handles public registration for commercial use of CompliPay.
 * Required by the Controlled Open Source License (COSL).
 */
class LicenseRegistrationController extends Controller
{
    public function __construct(
        private readonly LicenseRegistrationService $registrationService
    ) {}

    /**
     * Submit a new license registration.
     *
     * @unauthenticated
     *
     * @group License Registration
     *
     * @bodyParam organization_name string required The organization name. Example: Acme Corporation
     * @bodyParam contact_name string required Contact person name. Example: John Doe
     * @bodyParam contact_email string required Contact email. Example: john@acme.com
     * @bodyParam vat_number string Saudi VAT number (15 digits starting with 3). Example: 300000000000003
     * @bodyParam use_case_description string required Description of intended use. Example: ERP integration for retail invoicing
     * @bodyParam terms_accepted boolean required Must be true to proceed. Example: true
     * @bodyParam license_type string License type: commercial, educational, non-profit. Example: commercial
     * @bodyParam country_code string ISO country code. Example: SA
     * @bodyParam industry string Industry sector. Example: Retail
     * @bodyParam company_size string Company size: small, medium, large, enterprise. Example: medium
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Registration submitted successfully. Please check your email to verify.",
     *   "data": {
     *     "id": "uuid",
     *     "organization_name": "Acme Corporation",
     *     "status": "pending"
     *   }
     * }
     */
    public function register(LicenseRegistrationRequest $request): JsonResponse
    {
        $registration = $this->registrationService->register($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Registration submitted successfully. Please check your email to verify your registration.',
            'data' => [
                'id' => $registration->id,
                'organization_name' => $registration->organization_name,
                'status' => $registration->status,
                'terms_version' => $registration->terms_version,
            ],
        ], 201);
    }

    /**
     * Verify email address.
     *
     * @unauthenticated
     *
     * @group License Registration
     *
     * @urlParam token string required The verification token from the email.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Email verified successfully."
     * }
     */
    public function verify(string $token): JsonResponse
    {
        $registration = $this->registrationService->verify($token);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully. Your registration is pending review.',
            'data' => [
                'id' => $registration->id,
                'status' => $registration->status,
            ],
        ]);
    }

    /**
     * Check registration status.
     *
     * @unauthenticated
     *
     * @group License Registration
     *
     * @urlParam id string required The registration ID.
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "status": "approved",
     *     "verified": true
     *   }
     * }
     */
    public function status(string $id): JsonResponse
    {
        $registration = LicenseRegistration::find($id);

        if (! $registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $registration->id,
                'organization_name' => $registration->organization_name,
                'status' => $registration->status,
                'verified' => $registration->isVerified(),
                'approved_at' => $registration->approved_at?->toIso8601String(),
                'rejection_reason' => $registration->status === LicenseRegistration::STATUS_REJECTED
                    ? $registration->rejection_reason
                    : null,
            ],
        ]);
    }

    /**
     * Get terms of use info.
     *
     * @unauthenticated
     *
     * @group License Registration
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "current_version": "1.0",
     *     "terms_url": "/terms",
     *     "license_url": "/license"
     *   }
     * }
     */
    public function terms(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'current_version' => LicenseRegistrationService::CURRENT_TERMS_VERSION,
                'terms_url' => url('/terms'),
                'license_url' => url('/license'),
                'security_url' => url('/security'),
                'effective_date' => '2026-01-31',
                'acceptance_required' => true,
            ],
        ]);
    }

    /**
     * Check if an email is already registered.
     *
     * @unauthenticated
     *
     * @group License Registration
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $registered = $this->registrationService->isRegistered($request->email);

        return response()->json([
            'success' => true,
            'data' => [
                'registered' => $registered,
            ],
        ]);
    }
}
