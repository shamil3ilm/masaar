<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\ComplianceFindings;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RuleMeanings;
use Tests\TestCase;

/**
 * A ZATCA rule code said in words.
 *
 * The walk was told "ZATCA accepted the invoice and advises checking rule
 * BR-KSA-63 for the next one", with no hint that BR-KSA-63 is the buyer's
 * national address.
 */
class RuleMeaningTest extends TestCase
{
    /** Every code this app cites, and one of each family it does not. */
    private const CODES = [
        'BR-KSA-03', 'BR-KSA-04', 'BR-KSA-05', 'BR-KSA-06', 'BR-KSA-07', 'BR-KSA-08', 'BR-KSA-09', 'BR-KSA-11', 'BR-KSA-15',
        'BR-KSA-16', 'BR-KSA-17', 'BR-KSA-18', 'BR-KSA-19', 'BR-KSA-20', 'BR-KSA-23', 'BR-KSA-24', 'BR-KSA-25', 'BR-KSA-26',
        'BR-KSA-27', 'BR-KSA-28', 'BR-KSA-31', 'BR-KSA-34', 'BR-KSA-35', 'BR-KSA-36', 'BR-KSA-37', 'BR-KSA-39', 'BR-KSA-40',
        'BR-KSA-44', 'BR-KSA-46', 'BR-KSA-49', 'BR-KSA-51', 'BR-KSA-56', 'BR-KSA-60', 'BR-KSA-63', 'BR-KSA-66', 'BR-KSA-67',
        'BR-KSA-68', 'BR-KSA-69', 'BR-KSA-71', 'BR-KSA-72', 'BR-KSA-73', 'BR-KSA-74', 'BR-KSA-81', 'BR-KSA-83', 'BR-KSA-84',
        'BR-KSA-92', 'BR-KSA-F-04', 'BR-KSA-F-06', 'BR-KSA-F-13', 'BR-KSA-CL-01', 'BR-KSA-CL-04', 'BR-KSA-CL-06',
        'BR-KSA-DEC-01', 'BR-KSA-EN16931-01', 'BR-KSA-EN16931-03', 'BR-KSA-EN16931-11', 'BR-06', 'BR-16', 'BR-53',
        'BR-S-05', 'BR-Z-05', 'BR-S-09', 'BR-CO-15', 'BR-KSA-F-99', 'BR-KSA-CL-99', 'BR-CO-99', 'BR-KSA-99',
    ];

    public function test_every_code_reads_in_arabic(): void
    {
        app()->setLocale('ar');

        foreach (self::CODES as $code) {
            // Short forms such as UUID may stay in capitals; no English words.
            $this->assertDoesNotMatchRegularExpression('/[a-z]/', RuleMeanings::explain($code), $code);
        }
    }

    public function test_the_walks_warning_is_explained(): void
    {
        $plain = ComplianceFindings::advisory("BR-KSA-63: [BR-KSA-63] - If buyer country code is 'SA' and invoice is standard, then the buyer address must contain district");

        $this->assertSame(
            "Accepted. ZATCA notes: The buyer's national address is incomplete. A Saudi buyer on a standard invoice needs street, building number, postal code, city and district. Check this on your next invoice. (BR-KSA-63)",
            $plain,
        );
        $this->assertStringNotContainsString('advises checking rule', $plain);
    }

    public function test_a_rejection_is_explained(): void
    {
        $this->assertSame(
            "A Saudi buyer's postal code must be 5 digits. (BR-KSA-67)",
            ComplianceFindings::rejection('BR-KSA-67', 'buyer postal code must be 5 digits'),
        );
    }

    public function test_codes_found_in_any_form(): void
    {
        $this->assertSame('BR-KSA-F-06', RuleMeanings::code('[BR-KSA-F-06] - field too long'));
        $this->assertSame('BR-KSA-EN16931-11', RuleMeanings::code('BR-KSA-EN16931-11: line net'));
        $this->assertSame('BR-CO-15', RuleMeanings::code('BR-CO-15: totals'));
        $this->assertNull(RuleMeanings::code('no code here'));
    }
}
