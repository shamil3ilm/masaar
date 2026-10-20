<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Support;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A UAE organization's e-invoicing start date: the day from which it must
 * issue and transmit every invoice through its accredited service provider.
 *
 * In scope, "compliance will be achieved through working with an ASP"
 * (UAE Electronic Invoicing Guidelines v1.1, 6.1; the five-corner flow of
 * 5.1; Ministerial Decision No. 243 of 2025, Articles 5-6). The date comes
 * from the roll-out (8.3, by revenue) or is earlier for a business that
 * starts voluntarily (8.2), so it is the organization's own, never derived:
 * EinvoicingPhases is only a hint. None set: not yet in scope - its invoices
 * are ordinary tax invoices (VAT Executive Regulation, Article 59), kept,
 * and never transmitted, then or later.
 *
 * Held in the settings of its UAE compliance profile, beside the TRN and
 * endpoint the documents are built from. Every change is audited with who
 * made it and the date before and after.
 */
final class EinvoicingStart
{
    public const SETTING = 'einvoicing_start';

    public const AUDIT_ACTION = 'uae.einvoicing_start.changed';

    /** Dates are the UAE's own: a start date begins at midnight in the UAE. */
    public const TIMEZONE = 'Asia/Dubai';

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function date(Organization|string $organization): ?CarbonImmutable
    {
        $value = $this->profile($organization)?->settings[self::SETTING] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, self::TIMEZONE)->startOfDay() : null;
    }

    /**
     * Whether the organization must issue through its provider at a moment:
     * now, unless asked about another (an invoice's moment of issue).
     */
    public function inScope(Organization|string $organization, ?CarbonInterface $at = null): bool
    {
        $date = $this->date($organization);

        if ($date === null) {
            return false;
        }

        return CarbonImmutable::instance($at ?? now())->setTimezone(self::TIMEZONE)->startOfDay()->gte($date);
    }

    /**
     * Set or clear the date, recording who changed it from what to what.
     *
     * @throws DomainException when the organization has no UAE profile yet
     */
    public function set(Organization $organization, ?string $date, ?string $actorId): void
    {
        DB::transaction(function () use ($organization, $date, $actorId): void {
            $profile = ComplianceProfile::withoutTenantScope(fn (): ?ComplianceProfile => ComplianceProfile::query()
                ->where('org_id', $organization->id)
                ->where('jurisdiction', UaeSellerDetails::JURISDICTION)
                ->lockForUpdate()
                ->first())
                ?? throw new DomainException(__('Complete your UAE seller details first.'));

            $settings = (array) ($profile->settings ?? []);
            $old = $settings[self::SETTING] ?? null;
            $new = $date === null || $date === '' ? null : CarbonImmutable::parse($date)->toDateString();

            if ($old === $new) {
                return;
            }

            $profile->update(['settings' => [...$settings, self::SETTING => $new]]);

            $this->audit->logChange(
                self::AUDIT_ACTION,
                'ComplianceProfile',
                (string) $profile->id,
                [self::SETTING => $old],
                [self::SETTING => $new],
                userId: $actorId,
                organizationId: (string) $organization->id,
            );
        });
    }

    /** A UAE organization by its id; null for any other. */
    public function organization(string $id): ?Organization
    {
        $organization = Organization::query()->find($id);

        return UaePortal::offers($organization) ? $organization : null;
    }

    private function profile(Organization|string $organization): ?ComplianceProfile
    {
        $id = $organization instanceof Organization ? (string) $organization->id : $organization;

        return ComplianceProfile::withoutTenantScope(fn (): ?ComplianceProfile => ComplianceProfile::query()
            ->where('org_id', $id)
            ->where('jurisdiction', UaeSellerDetails::JURISDICTION)
            ->first());
    }
}
