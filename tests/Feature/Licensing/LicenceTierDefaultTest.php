<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A licence issued without a tier gets the starter one.
 *
 * `createWithCredentials()` says so in a comment directly above the code that
 * does not do it: the tier is read as `$attributes['tier'] instanceof
 * LicenseTier` before any default is applied, so omitting the key raises
 * "Undefined array key 'tier'". The `?? 'starter'` that looks like the guard
 * only protects the second branch of the ternary, which is never reached.
 *
 * Found while writing a licence fixture for the admin console. It matters
 * beyond fixtures: PHPUnit turns the warning into a failure, while production
 * logs it and carries on with whatever null resolves to - so the test suite
 * and the deployment disagree about what this code does.
 */
class LicenceTierDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_absent_tier_defaults(): void
    {
        $issued = License::createWithCredentials([
            'organization_name' => 'No Tier Co',
            'contact_email' => 'ops@notier.test',
        ]);

        $this->assertSame(LicenseTier::Starter, $issued['license']->tier);
    }

    /**
     * And the two forms that were already working still do: the enum itself,
     * and its string value.
     */
    public function test_a_given_tier_is_kept(): void
    {
        $fromEnum = License::createWithCredentials([
            'organization_name' => 'Enum Co',
            'contact_email' => 'ops@enum.test',
            'tier' => LicenseTier::Professional,
        ]);

        $fromString = License::createWithCredentials([
            'organization_name' => 'String Co',
            'contact_email' => 'ops@string.test',
            'tier' => LicenseTier::Professional->value,
        ]);

        $this->assertSame(LicenseTier::Professional, $fromEnum['license']->tier);
        $this->assertSame(LicenseTier::Professional, $fromString['license']->tier);
    }
}
