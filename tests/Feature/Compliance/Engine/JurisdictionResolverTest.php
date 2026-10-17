<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Which module an organization's country answers with, and which it is
 * refused.
 */
class JurisdictionResolverTest extends TestCase
{
    // Whether a country is on is a stored admin switch before it is a default.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
    }

    public function test_blank_country_resolves_to_saudi(): void
    {
        foreach ([null, ''] as $country) {
            $this->assertSame('SA', $this->resolve($country)->code());
        }
    }

    public function test_each_country_resolves_its_module(): void
    {
        $saudi = $this->resolve('SA');
        $uae = $this->resolve('AE');

        $this->assertSame(['SA', 'Saudi Arabia', 'Asia/Riyadh', 'SAR'], [$saudi->code(), $saudi->name(), $saudi->timezone(), $saudi->currency()]);
        $this->assertSame(['AE', 'United Arab Emirates', 'Asia/Dubai', 'AED'], [$uae->code(), $uae->name(), $uae->timezone(), $uae->currency()]);
    }

    public function test_switched_off_uae_is_refused(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $this->expectException(JurisdictionUnavailableException::class);
        $this->expectExceptionMessage('This deployment does not issue invoices in United Arab Emirates (AE).');

        $this->resolve('AE');
    }

    public function test_unknown_country_is_refused(): void
    {
        $this->expectException(JurisdictionUnavailableException::class);
        $this->expectExceptionMessage('No jurisdiction is registered for country EG');

        $this->resolve('EG');
    }

    public function test_registry_lists_registered_and_enabled(): void
    {
        $registry = app(JurisdictionRegistry::class);

        $this->assertSame(['AE', 'SA'], $registry->registered());
        $this->assertSame(['AE', 'SA'], $registry->enabled());

        config(['peppol.countries.AE.enabled' => false]);

        $this->assertSame(['AE', 'SA'], $registry->registered());
        $this->assertSame(['SA'], $registry->enabled());
    }

    public function test_country_cannot_register_twice(): void
    {
        $this->expectException(LogicException::class);

        app(JurisdictionRegistry::class)->register('SA', fn (): Jurisdiction => $this->resolve('SA'));
    }

    private function resolve(?string $country): Jurisdiction
    {
        return app(JurisdictionResolver::class)->forOrganization((new Organization)->forceFill(['country' => $country]));
    }
}
