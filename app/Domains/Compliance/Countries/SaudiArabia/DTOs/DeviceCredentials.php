<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\DTOs;

/**
 * What one EGS unit authenticates to ZATCA with.
 *
 * ZATCA has no platform login. Each device is onboarded on its own and
 * issued a binarySecurityToken and a secret, and every call it makes -
 * compliance checks with the compliance CSID, clearance and reporting with
 * the production one - is HTTP Basic with that pair.
 */
final readonly class DeviceCredentials
{
    public function __construct(
        public string $token,
        public string $secret,
        /**
         * The ZATCA environment that issued these, and so the gateway they
         * authenticate to. Null: the deployment's default.
         */
        public ?string $environment = null,
    ) {}
}
