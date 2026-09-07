<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use Tests\TestCase;

/**
 * csr.invoice.type says which invoice types a certificate may sign.
 *
 * Four positions, each a flag: standard, simplified, and two ZATCA reserves.
 * Both is 1100. The value was built by summing bit flags into a number and
 * zero-padding it, which produced 0001, 0002 and 0003 — none of them a value
 * ZATCA accepts.
 *
 * That was invisible for two reasons. The SDK rejects the config and still
 * exits 0, so the command announced success and then failed reading a CSR
 * nothing had written; and the SDK path was never taken at all, because it
 * was looking for the jar in a hardcoded Downloads folder. Fixing the path is
 * what exposed this.
 *
 * The values below are the ones in ZATCA's own sample configs, shipped in the
 * SDK as Data/Input/csr-config-example-*.properties.
 */
class CsrInvoiceTypeTest extends TestCase
{
    public function test_both_types_is_1100(): void
    {
        $this->assertSame('1100', $this->code(standard: true, simplified: true));
    }

    public function test_standard_only_is_1000(): void
    {
        $this->assertSame('1000', $this->code(standard: true, simplified: false));
    }

    public function test_simplified_only_is_0100(): void
    {
        $this->assertSame('0100', $this->code(standard: false, simplified: true));
    }

    /**
     * Each position is a flag in its own right. Summing them collapses
     * "standard and simplified" onto a single digit, which is how 1100 became
     * 0003 — a number, where ZATCA reads a pattern.
     */
    public function test_the_positions_are_flags(): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$standard, $simplified]) {
            $code = $this->code($standard, $simplified);

            $this->assertSame(4, strlen($code));
            $this->assertSame($standard ? '1' : '0', $code[0]);
            $this->assertSame($simplified ? '1' : '0', $code[1]);
            $this->assertSame('00', substr($code, 2), 'The last two positions are reserved and always zero.');
        }
    }

    private function code(bool $standard, bool $simplified): string
    {
        return (new CsrData(
            organizationName: 'Acme',
            organizationUnit: 'IT',
            commonName: 'EGS1-TEST-001',
            vatNumber: '399999999900003',
            serialNumber: '1-Solution|2-1.0|3-abc',
            location: 'Riyadh',
            industry: 'Information Technology',
            invoiceTypesStandard: $standard,
            invoiceTypesSimplified: $simplified,
        ))->getInvoiceTypeCode();
    }
}
