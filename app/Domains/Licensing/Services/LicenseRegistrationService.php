<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\Models\LicenseRegistration;
use App\Domains\Licensing\Models\LicenseRegistrationAudit;
use App\Notifications\LicenseRegistrationApproved;
use App\Notifications\LicenseRegistrationPending;
use App\Notifications\LicenseRegistrationRejected;
use App\Notifications\LicenseVerificationEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * License Registration Service.
 *
 * Manages the lifecycle of license registrations including:
 * - Registration submission
 * - Email verification
 * - Approval/rejection workflow
 * - Audit logging
 * - Compliance tracking
 */
class LicenseRegistrationService
{
    /**
     * Current terms version.
     */
    public const CURRENT_TERMS_VERSION = '1.0';

    /**
     * Submit a new license registration.
     *
     * @param array $data Registration data
     * @return LicenseRegistration
     */
    public function register(array $data): LicenseRegistration
    {
        return DB::transaction(function () use ($data) {
            $registration = LicenseRegistration::create([
                'organization_name' => $data['organization_name'],
                'contact_name' => $data['contact_name'],
                'contact_email' => $data['contact_email'],
                'vat_number' => $data['vat_number'] ?? null,
                'use_case_description' => $data['use_case_description'],
                'terms_accepted' => $data['terms_accepted'] ?? false,
                'terms_accepted_at' => $data['terms_accepted'] ? now() : null,
                'terms_version' => self::CURRENT_TERMS_VERSION,
                'accepted_from_ip' => request()->ip(),
                'status' => LicenseRegistration::STATUS_PENDING,
                'license_type' => $data['license_type'] ?? LicenseRegistration::TYPE_COMMERCIAL,
                'country_code' => $data['country_code'] ?? 'SA',
                'industry' => $data['industry'] ?? null,
                'company_size' => $data['company_size'] ?? null,
            ]);

            // Generate verification token
            $registration->generateVerificationToken();

            // Log the creation
            LicenseRegistrationAudit::create([
                'registration_id' => $registration->id,
                'action' => LicenseRegistrationAudit::ACTION_CREATED,
                'new_status' => LicenseRegistration::STATUS_PENDING,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Log for compliance
            Log::channel('audit')->info('License registration submitted', [
                'registration_id' => $registration->id,
                'organization_name' => $registration->organization_name,
                'contact_email' => $registration->contact_email,
                'vat_number' => $registration->vat_number,
                'ip_address' => request()->ip(),
            ]);

            // Send verification email
            $this->sendVerificationEmail($registration);

            // Notify admins of pending registration
            $this->notifyAdminsPendingRegistration($registration);

            return $registration;
        });
    }

    /**
     * Verify a registration email.
     *
     * @param string $token Verification token
     * @return LicenseRegistration|null
     */
    public function verify(string $token): ?LicenseRegistration
    {
        $registration = LicenseRegistration::where('verification_token', $token)
            ->whereNull('verified_at')
            ->first();

        if (! $registration) {
            return null;
        }

        $registration->verify();

        LicenseRegistrationAudit::create([
            'registration_id' => $registration->id,
            'action' => LicenseRegistrationAudit::ACTION_VERIFIED,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        Log::channel('audit')->info('License registration verified', [
            'registration_id' => $registration->id,
            'contact_email' => $registration->contact_email,
        ]);

        return $registration;
    }

    /**
     * Send verification email.
     */
    public function sendVerificationEmail(LicenseRegistration $registration): void
    {
        // In production, this would send an actual email
        // Notification::route('mail', $registration->contact_email)
        //     ->notify(new LicenseVerificationEmail($registration));

        Log::info('Verification email would be sent', [
            'registration_id' => $registration->id,
            'email' => $registration->contact_email,
            'token' => $registration->verification_token,
        ]);
    }

    /**
     * Notify admins of pending registration.
     */
    protected function notifyAdminsPendingRegistration(LicenseRegistration $registration): void
    {
        // In production, notify admin users
        // $admins = User::where('is_admin', true)->get();
        // Notification::send($admins, new LicenseRegistrationPending($registration));

        Log::info('Admin notification would be sent for pending registration', [
            'registration_id' => $registration->id,
        ]);
    }

    /**
     * Get registration statistics.
     */
    public function getStatistics(): array
    {
        return [
            'total' => LicenseRegistration::count(),
            'pending' => LicenseRegistration::where('status', LicenseRegistration::STATUS_PENDING)->count(),
            'approved' => LicenseRegistration::where('status', LicenseRegistration::STATUS_APPROVED)->count(),
            'rejected' => LicenseRegistration::where('status', LicenseRegistration::STATUS_REJECTED)->count(),
            'suspended' => LicenseRegistration::where('status', LicenseRegistration::STATUS_SUSPENDED)->count(),
            'revoked' => LicenseRegistration::where('status', LicenseRegistration::STATUS_REVOKED)->count(),
            'verified' => LicenseRegistration::whereNotNull('verified_at')->count(),
            'by_country' => LicenseRegistration::groupBy('country_code')
                ->selectRaw('country_code, count(*) as count')
                ->pluck('count', 'country_code')
                ->toArray(),
            'by_type' => LicenseRegistration::groupBy('license_type')
                ->selectRaw('license_type, count(*) as count')
                ->pluck('count', 'license_type')
                ->toArray(),
        ];
    }

    /**
     * Check if an organization/email is registered.
     */
    public function isRegistered(string $email): bool
    {
        return LicenseRegistration::where('contact_email', $email)
            ->where('status', LicenseRegistration::STATUS_APPROVED)
            ->exists();
    }

    /**
     * Check if a VAT number is already registered.
     */
    public function isVatRegistered(string $vatNumber): bool
    {
        return LicenseRegistration::where('vat_number', $vatNumber)
            ->whereIn('status', [
                LicenseRegistration::STATUS_PENDING,
                LicenseRegistration::STATUS_APPROVED,
            ])
            ->exists();
    }

    /**
     * Export registrations for compliance reporting.
     */
    public function exportForCompliance(?\DateTime $from = null, ?\DateTime $to = null): array
    {
        $query = LicenseRegistration::with(['audits', 'approver'])
            ->orderBy('created_at', 'desc');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->get()->map(function ($registration) {
            return [
                'id' => $registration->id,
                'organization_name' => $registration->organization_name,
                'contact_name' => $registration->contact_name,
                'contact_email' => $registration->contact_email,
                'vat_number' => $registration->vat_number,
                'country_code' => $registration->country_code,
                'license_type' => $registration->license_type,
                'status' => $registration->status,
                'terms_version' => $registration->terms_version,
                'terms_accepted_at' => $registration->terms_accepted_at?->toIso8601String(),
                'verified_at' => $registration->verified_at?->toIso8601String(),
                'approved_at' => $registration->approved_at?->toIso8601String(),
                'approved_by' => $registration->approver?->name,
                'created_at' => $registration->created_at->toIso8601String(),
                'audit_count' => $registration->audits->count(),
            ];
        })->toArray();
    }
}
