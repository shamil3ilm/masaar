<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A UAE seller's details, checked in PINT AE's shapes and stored where
 * PintMapper reads them. A Saudi seller's are in JurisdictionIsolationTest.
 */
class UaeSellerDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** A UAE address has no four-digit building number or five-digit postal code. */
    public function test_uae_address_without_building_number_passes(): void
    {
        $this->assertSame([], $this->errors($this->details()));
    }

    public function test_bad_endpoint_is_refused(): void
    {
        $this->assertArrayHasKey('endpoint_id', $this->errors($this->details(['endpoint_id' => '1001234567'])));
        $this->assertArrayHasKey('endpoint_id', $this->errors($this->details(['endpoint_id' => '0088:1001234567'])));
        $this->assertArrayHasKey('endpoint_id', $this->errors($this->details(['endpoint_id' => '0235:12345'])));
    }

    public function test_emirate_and_trn_shapes_are_checked(): void
    {
        $errors = $this->errors($this->details(['emirate' => 'Dubai', 'trn' => '300000000000003']));

        $this->assertArrayHasKey('emirate', $errors);
        $this->assertArrayHasKey('trn', $errors);
    }

    public function test_tin_must_match_the_trn(): void
    {
        $this->assertArrayHasKey('tin', $this->errors($this->details(['tin' => '1009999999'])));
        $this->assertSame([], $this->errors($this->details(['tin' => '1001234567'])));
    }

    /** A seller not registered for VAT has no TRN, and gives its TIN instead. */
    public function test_seller_without_trn_gives_tin(): void
    {
        $this->assertArrayHasKey('tin', $this->errors($this->details(['trn' => null])));
        $this->assertSame([], $this->errors($this->details(['trn' => null, 'tin' => '1001234567'])));
    }

    public function test_registration_authority_follows_its_type(): void
    {
        $this->assertArrayHasKey('legal_registration_authority', $this->errors($this->details(['legal_registration_authority' => null])));
        $this->assertArrayHasKey('legal_registration_authority', $this->errors($this->details(['legal_registration_type' => 'PAS', 'legal_registration_authority' => 'India'])));
        $this->assertSame([], $this->errors($this->details(['legal_registration_type' => 'PAS', 'legal_registration_authority' => 'IN'])));
        $this->assertSame([], $this->errors($this->details(['legal_registration_type' => 'EID', 'legal_registration_authority' => null])));
    }

    public function test_details_land_where_the_mapper_reads(): void
    {
        $organization = Organization::create(['name' => 'Gulf', 'country' => 'AE']);

        $profile = app(UaeSellerDetails::class)->save($organization, $this->details());

        $this->assertSame([ComplianceProfile::STATUS_ACTIVE, 'AE'], [$profile->status, $profile->jurisdiction]);
        $this->assertSame('1001234567', $profile->setting('tin'));
        $this->assertSame('0235:1001234567', $profile->setting('endpoint_id'));
        $this->assertSame('DXB', $profile->setting('emirate'));
        $this->assertSame($profile->id, $organization->complianceProfileFor('AE')?->id);
        $this->assertSame(['Sheikh Zayed Road', 'Dubai', null], [$organization->fresh()->street, $organization->fresh()->city, $organization->fresh()->building_number]);

        app(UaeSellerDetails::class)->save($organization, $this->details(['emirate' => 'AUH']));

        $this->assertSame(1, ComplianceProfile::query()->where('org_id', $organization->id)->count());
        $this->assertSame('AUH', $organization->complianceProfileFor('AE')?->setting('emirate'));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, list<string>>
     */
    private function errors(array $details): array
    {
        return Validator::make($details, CompanyProfileRules::rules('AE', complete: true), CompanyProfileRules::messages('AE'))->errors()->toArray();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return array_filter(array_merge([
            'name' => 'Gulf Trading LLC',
            'street' => 'Sheikh Zayed Road',
            'city' => 'Dubai',
            'emirate' => 'DXB',
            'trn' => '100123456700003',
            'endpoint_id' => '0235:1001234567',
            'legal_registration_type' => 'TL',
            'legal_registration_id' => 'CN-1234567',
            'legal_registration_authority' => 'Dubai Economy and Tourism',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }
}
