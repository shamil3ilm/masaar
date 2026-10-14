<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An unlimited licence files without limit.
 *
 * "Unlimited" was written two ways and neither worked. The licence API took
 * -1 and the Unlimited tier used PHP_INT_MAX, into unsigned INT columns whose
 * ceiling is 4,294,967,295. On MySQL, which runs strict, both are out of
 * range: setting a licence to unlimited was a 500. On SQLite, which the suite
 * runs on, -1 was stored as given - and the quota check, used >= limit, read
 * 0 >= -1 as exhausted and refused the first invoice. The suite saw only the
 * second failure and nothing tested either.
 */
class UnlimitedLicenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The value has to fit the column, or MySQL refuses the write.
     */
    public function test_unlimited_fits_the_column(): void
    {
        $this->assertSame(4294967295, License::UNLIMITED, 'the ceiling of an unsigned INT');

        foreach (LicenseTier::cases() as $tier) {
            foreach (['invoices_per_month', 'calls_per_day', 'max_organizations'] as $limit) {
                $value = $tier->getDefaults()[$limit];

                $this->assertGreaterThanOrEqual(0, $value, "{$tier->value} {$limit} is negative");
                $this->assertLessThanOrEqual(License::UNLIMITED, $value, "{$tier->value} {$limit} overflows its column");
            }
        }
    }

    /**
     * -1 is still accepted, as the API documents, and stored in range.
     */
    public function test_minus_one_is_stored_in_range(): void
    {
        $license = $this->licence(['invoices_per_month' => -1, 'calls_per_day' => -1, 'max_organizations' => -1]);

        $this->assertSame(License::UNLIMITED, $license->fresh()->invoices_per_month);
        $this->assertSame(License::UNLIMITED, $license->fresh()->calls_per_day);
        $this->assertSame(License::UNLIMITED, $license->fresh()->max_organizations);
        $this->assertTrue($license->fresh()->isUnlimited('invoices_per_month'));
    }

    public function test_an_unlimited_licence_files(): void
    {
        $license = $this->licence(['invoices_per_month' => -1]);

        $metering = app(UsageMeteringService::class);

        $metering->checkInvoiceQuota($license->fresh());
        $metering->reserveInvoiceQuota($license->fresh());

        $this->assertSame(License::UNLIMITED, $license->fresh()->invoices_per_month);
    }

    public function test_the_unlimited_tier_is_unlimited(): void
    {
        $license = $this->licence(['tier' => LicenseTier::Unlimited]);

        $this->assertTrue($license->fresh()->isUnlimited('invoices_per_month'));

        app(UsageMeteringService::class)->reserveInvoiceQuota($license->fresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function licence(array $attributes): License
    {
        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);

        return License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
            ...$attributes,
        ])['license'];
    }
}
