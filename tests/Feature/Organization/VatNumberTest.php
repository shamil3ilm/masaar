<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The VAT number has to survive a write and come back out.
 *
 * ZATCA needs it for seller BT-31 and for tag 2 of the QR code, so an
 * organization whose VAT number reads as null cannot submit an invoice at
 * all. Two separate faults made that the normal case: the column was absent
 * from $fillable so the onboarding API's value was dropped, and the accessor
 * read only from the compliance profile, never falling back to the column.
 *
 * Neither showed up in unit tests, because nothing asserted the round trip.
 */
class VatNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_number_survives_mass_assignment(): void
    {
        $organization = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $this->assertSame('300000000000003', $organization->fresh()->vat_number);
    }

    public function test_vat_number_is_stored_in_its_column(): void
    {
        $organization = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'vat_number' => '300000000000003',
        ]);
    }

    /**
     * One organization can hold different registrations per jurisdiction, so a
     * compliance profile takes precedence over the stored column.
     */
    public function test_compliance_profile_overrides_the_column(): void
    {
        $organization = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['vat_number' => '311111111111113'],
        ]);

        $this->assertSame('311111111111113', $organization->fresh()->vat_number);
    }

    public function test_missing_vat_number_reads_as_null(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->assertNull($organization->fresh()->vat_number);
    }
}
