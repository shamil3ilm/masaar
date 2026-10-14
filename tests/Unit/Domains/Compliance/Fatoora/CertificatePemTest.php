<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Fatoora\Helpers\CertificatePem;
use PHPUnit\Framework\TestCase;

/**
 * Every shape a stored certificate arrives in comes out as the same PEM.
 */
class CertificatePemTest extends TestCase
{
    private const BODY = 'MIIBszCCAVmgAwIBAgIGAZK8wR2cMAoGCCqGSM49BAMCMBUxEzARBgNVBAMMCmVJbnZvaWNpbmc=';

    public function test_a_zatca_token_is_unwrapped(): void
    {
        $this->assertSame($this->pem(), CertificatePem::from(base64_encode(self::BODY)));
    }

    public function test_a_bare_body_is_wrapped(): void
    {
        $this->assertSame($this->pem(), CertificatePem::from(self::BODY));
    }

    public function test_a_pem_is_left_alone(): void
    {
        $this->assertSame($this->pem(), CertificatePem::from($this->pem()));
    }

    public function test_nothing_stays_nothing(): void
    {
        $this->assertNull(CertificatePem::from(null));
        $this->assertNull(CertificatePem::from('  '));
    }

    private function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(self::BODY, 64, "\n")."-----END CERTIFICATE-----\n";
    }
}
