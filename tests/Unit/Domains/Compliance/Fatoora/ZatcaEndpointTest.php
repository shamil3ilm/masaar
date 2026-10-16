<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Licensing\Enums\LicenseEnvironment;
use Tests\TestCase;

/**
 * ZATCA's addresses are settings: a moved endpoint is a change to the
 * environment, not a release.
 */
class ZatcaEndpointTest extends TestCase
{
    public function test_the_gateway_follows_its_setting(): void
    {
        config(['fatoora.endpoints.sandbox' => 'https://gateway.example/sandbox']);

        $this->assertSame('https://gateway.example/sandbox', LicenseEnvironment::Sandbox->getZatcaBaseUrl());
    }

    public function test_the_published_address_is_the_default(): void
    {
        $this->assertSame('https://gw-fatoora.zatca.gov.sa/e-invoicing/core', config('fatoora.endpoints.production'));
    }
}
