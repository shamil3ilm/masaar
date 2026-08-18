<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSR fields are tenant-supplied and get written into an OpenSSL config file.
 * A newline ends the current directive, so an unvalidated organization name
 * can inject a [section] or override a key and change what the certificate
 * request asks for.
 */
class CsrDataTest extends TestCase
{
    public function test_valid_data_is_accepted(): void
    {
        $csr = $this->make();

        $this->assertSame('Acme Trading Co', $csr->organizationName);
        $this->assertSame('VATSA-300000000000003', $csr->getOrganizationIdentifier());
    }

    #[DataProvider('injectionProvider')]
    public function test_config_injection_is_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(['organizationName' => $value]);
    }

    public static function injectionProvider(): array
    {
        return [
            'newline then section' => ["Acme\n[zatca_req_ext]"],
            'carriage return' => ["Acme\rbasicConstraints = CA:TRUE"],
            'section header' => ['Acme[req]'],
            'key assignment' => ['Acme = evil'],
            'comment' => ['Acme # ignore the rest'],
            'semicolon comment' => ['Acme ; ignore'],
            'openssl variable' => ['Acme $ENV::PATH'],
            'backslash' => ['Acme\\evil'],
            'quote' => ['Acme"evil'],
            'null byte' => ["Acme\0evil"],
        ];
    }

    public function test_empty_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(['commonName' => '   ']);
    }

    public function test_overlong_field_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(['location' => str_repeat('a', 129)]);
    }

    #[DataProvider('badVatProvider')]
    public function test_vat_number_must_be_fifteen_digits(string $vat): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->make(['vatNumber' => $vat]);
    }

    public static function badVatProvider(): array
    {
        return [
            'too short' => ['3000000000'],
            'too long' => ['3000000000000031'],
            'letters' => ['30000000000000X'],
            'empty' => [''],
            'spaced' => ['300 000 000 000 003'],
        ];
    }

    /**
     * Arabic names are normal input here and must not be caught by the filter.
     */
    public function test_arabic_organization_name_is_accepted(): void
    {
        $csr = $this->make(['organizationName' => 'شركة الاختبار المحدودة']);

        $this->assertSame('شركة الاختبار المحدودة', $csr->organizationName);
    }

    private function make(array $overrides = []): CsrData
    {
        $defaults = [
            'organizationName' => 'Acme Trading Co',
            'organizationUnit' => 'Riyadh Branch',
            'commonName' => 'EGS-1234567890',
            'vatNumber' => '300000000000003',
            'serialNumber' => '1-Masaar|2-1.0|3-abc123',
            'location' => 'Riyadh',
            'industry' => 'Retail',
        ];

        return new CsrData(...array_merge($defaults, $overrides));
    }
}
