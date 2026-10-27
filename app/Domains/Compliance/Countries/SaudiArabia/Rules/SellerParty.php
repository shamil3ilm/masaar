<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;

/**
 * The seller's address and identification on a document, from the device
 * that signs it - the same rule for the compliance samples a device is
 * certified with and for every document it then issues.
 *
 * - Name: always the organization's legal name as registered; "the tax
 *   invoice must be issued in the name of the head office as registered on
 *   the registration certificate. The branch name may also be included"
 *   (Guideline for Tax Invoicing and Records, v3, FAQ 15, p57).
 * - Address: "the supplier's principal place of business or any other
 *   relevant establishment" (same Guideline, 4.2 Supplier address, p19; VAT
 *   IR Art 53(5)). Either is allowed; one is chosen and kept for every
 *   device: the organization's registered national address, its principal
 *   place of business. Documents always carried it, and the tills' compliance
 *   samples now do too, so a till is certified with the address it signs.
 * - Seller ID (BT-29): "in case of multiple commercial registrations, the
 *   seller should fill the commercial registration of the branch in respect
 *   of which the Tax Invoice is being issued" (XML Implementation Standard
 *   v1.2, BR-KSA-08 notes, p51-52). A branch's own CR when it has one, under
 *   CRN - first in BR-KSA-08's order - else the organization's.
 *
 * The samples used the branch's address while issued documents used head
 * office's, so a till was certified with one address and signed another;
 * and no document carried a branch's own CR.
 */
final class SellerParty
{
    /** The branch whose till is the device, or null for head office's. */
    public static function branchOf(Organization $organization, ?string $deviceId): ?Branch
    {
        if ($deviceId === null || $deviceId === (string) $organization->id) {
            return null;
        }

        $unit = EgsUnit::withoutTenantScope(fn (): ?EgsUnit => EgsUnit::query()->with('branch')->find($deviceId));

        return $unit === null || $unit->isHeadOffice() ? null : $unit->branch;
    }

    public static function address(Organization $organization): AddressData
    {
        return $organization->getAddressData();
    }

    public static function crNumber(Organization $organization, ?Branch $branch): ?string
    {
        return filled($branch?->cr_number) ? (string) $branch->cr_number : $organization->cr_number;
    }

    public static function scheme(Organization $organization, ?Branch $branch): string
    {
        return filled($branch?->cr_number) ? SellerIdentification::DEFAULT : SellerIdentification::scheme($organization);
    }
}
