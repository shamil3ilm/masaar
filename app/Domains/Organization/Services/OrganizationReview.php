<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Services\FreePeriod;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Services\LicenseManagementService;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Mail\OrganizationReviewedMail;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A platform admin's decision on a business that signed itself up.
 *
 * Approving assigns its plan - an active licence for the organization - and
 * opens ZATCA onboarding and issuing. A plan approved without an end date
 * runs for the free period (FreePeriod: one month at launch), which nothing
 * is invoiced for. Turning it down records why. Either
 * way the organization's admins are told by email, after the decision is
 * committed, so a mail failure cannot undo it.
 */
class OrganizationReview
{
    public function __construct(
        private readonly LicenseManagementService $licences,
        private readonly AuditService $audit,
        private readonly FreePeriod $freePeriod,
    ) {}

    /**
     * @return array{organization: Organization, licence_id: string, api_secret: string}
     *
     * @throws DomainException when the organization is not awaiting a decision
     */
    public function approve(string $organizationId, string $adminId, string $tier, ?string $expiresAt = null): array
    {
        $result = DB::transaction(function () use ($organizationId, $adminId, $tier, $expiresAt): array {
            $organization = $this->awaiting($organizationId);
            $contact = $this->admins($organization)[0] ?? null;
            $expiresAt ??= $this->freePeriod->endsFrom(CarbonImmutable::now(), (string) $organization->id)?->toDateTimeString();

            $issued = $this->licences->createLicense([
                'org_id' => $organization->id,
                'organization_name' => $organization->name,
                'organization_vat' => $organization->vat_number,
                'contact_email' => $contact ?? 'unknown@invalid',
                'contact_phone' => null,
                'tier' => $tier,
                'status' => LicenseStatus::Active->value,
                'expires_at' => $expiresAt,
                'notes' => 'Assigned when the organization was approved.',
            ]);

            $this->decide($organization, Organization::STATUS_ACTIVE, $adminId, null);

            return [
                'organization' => $organization,
                'licence_id' => (string) $issued['license']->id,
                'api_secret' => (string) $issued['api_secret'],
            ];
        });

        $this->tell($result['organization'], approved: true, note: null);

        return $result;
    }

    /**
     * @throws DomainException when the organization is not awaiting a decision
     */
    public function reject(string $organizationId, string $adminId, string $reason): Organization
    {
        $organization = DB::transaction(function () use ($organizationId, $adminId, $reason): Organization {
            $organization = $this->awaiting($organizationId);

            $this->decide($organization, Organization::STATUS_REJECTED, $adminId, $reason);

            return $organization;
        });

        $this->tell($organization, approved: false, note: $reason);

        return $organization;
    }

    /**
     * Locked, and only while a decision is due: a second click on approve
     * would otherwise issue a second licence.
     */
    private function awaiting(string $organizationId): Organization
    {
        $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();

        if ($organization === null) {
            throw new DomainException('No such organization.');
        }

        if (! in_array($organization->status, [Organization::STATUS_PENDING_REVIEW, Organization::STATUS_REJECTED], true)) {
            throw new DomainException('This organization is '.$organization->status.', not awaiting review.');
        }

        return $organization;
    }

    private function decide(Organization $organization, string $status, string $adminId, ?string $note): void
    {
        $oldValues = $organization->toArray();

        $organization->forceFill([
            'status' => $status,
            'reviewed_at' => now(),
            'reviewed_by' => $adminId,
            'review_note' => $note,
        ])->save();

        $this->audit->logUpdated($organization, $oldValues);
    }

    /**
     * @return list<string>
     */
    private function admins(Organization $organization): array
    {
        return $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->pluck('email')
            ->all();
    }

    private function tell(Organization $organization, bool $approved, ?string $note): void
    {
        // One each, so each is written in its reader's language.
        $recipients = $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->get();

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new OrganizationReviewedMail(
                    organizationName: (string) $organization->name,
                    approved: $approved,
                    note: $note,
                    portalUrl: route('portal.dashboard'),
                ));
            } catch (Throwable $e) {
                Log::error('Organization review: the email could not be sent', [
                    'org_id' => $organization->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
