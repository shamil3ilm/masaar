<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Support\UnitCode;
use Tests\TestCase;

/**
 * The code lists the API accepts are the ones the specifications name, read
 * from the official artefacts themselves rather than copied by hand:
 *
 * - ZATCA's Schematron in its SDK (ZATCA_SDK_PATH): BR-KSA-16 payment means,
 *   BR-KSA-CL-01 currencies (ISO 4217:2005), BR-KSA-CL-06 charge reasons
 *   (UNCL 7161), BR-KSA-18 tax categories (UNCL 5305 subset);
 * - PINT AE's genericode lists (PEPPOL_ARTEFACTS_PATH): UNCL4461 "Payment
 *   means codes used in AE" (D.16B), ISO4217 (2015), UNCL7161 (D.16B),
 *   Aligned-TaxCategoryCodes (D.16B) and UNECERec20 (11e, with Rec 21).
 *
 * Each skips without its artefacts, as the conformance tests do.
 */
class CodeListConformanceTest extends TestCase
{
    public function test_saudi_lists_are_the_schematrons(): void
    {
        $xsl = $this->zatcaRules();

        $this->assertSame($this->sorted($this->listIn($xsl, 'BR-KSA-16')), $this->sorted(FatooraConfig::acceptedPaymentMeans()), 'BR-KSA-16');
        $this->assertSame($this->sorted($this->listIn($xsl, 'BR-KSA-CL-01')), $this->sorted(FatooraConfig::CURRENCIES), 'BR-KSA-CL-01');
        $this->assertSame([], array_values(array_diff(FatooraConfig::CHARGE_REASON_CODES, $this->listIn($xsl, 'BR-KSA-CL-06'))), 'BR-KSA-CL-06: a charge reason ZATCA does not take');
    }

    public function test_uae_lists_are_pint_aes(): void
    {
        $lists = $this->pintLists();

        $this->assertSame($this->sorted($this->codes($lists.'/UNCL4461.gc')), $this->sorted(PintAe::PAYMENT_MEANS), 'UNCL4461.gc');
        $this->assertSame($this->sorted($this->codes($lists.'/ISO4217.gc')), $this->sorted(PintAe::CURRENCIES), 'ISO4217.gc');
        $this->assertSame($this->sorted($this->codes($lists.'/UNCL7161.gc')), $this->sorted(PintAe::CHARGE_REASON_CODES), 'UNCL7161.gc');
        // The list writes N as a Greek capital Nu; the Schematron, and so
        // every document, a Latin one.
        $this->assertSame(
            $this->sorted(array_map(fn (string $code): string => str_replace('Ν', 'N', $code), $this->codes($lists.'/Aligned-TaxCategoryCodes.gc'))),
            $this->sorted(array_map(fn (PintTaxCategory $category): string => $category->value, PintTaxCategory::cases())),
            'Aligned-TaxCategoryCodes.gc',
        );
    }

    /** Every unit an invoice may name is UN/ECE Recommendation 20 (or 21) as PINT AE's 11e list has it. */
    public function test_units_are_recommendation_20(): void
    {
        $rec20 = $this->codes($this->pintLists().'/UNECERec20.gc');
        $canonical = array_unique(array_map(fn (string $code): string => UnitCode::normalise($code), UnitCode::accepted()));

        $this->assertSame([], array_values(array_diff($canonical, $rec20)));
    }

    private function zatcaRules(): string
    {
        $sdk = (string) getenv('ZATCA_SDK_PATH');
        $file = $sdk === '' ? '' : $sdk.'/Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl';

        if ($file === '' || ! is_file($file)) {
            $this->markTestSkipped('Set ZATCA_SDK_PATH to compare with ZATCA\'s Schematron.');
        }

        return (string) file_get_contents($file);
    }

    private function pintLists(): string
    {
        $artefacts = (string) getenv('PEPPOL_ARTEFACTS_PATH');
        $lists = $artefacts === '' ? '' : $artefacts.'/billing/trn-invoice/codelist';

        if ($lists === '' || ! is_dir($lists)) {
            $this->markTestSkipped('Set PEPPOL_ARTEFACTS_PATH to compare with PINT AE\'s code lists.');
        }

        return $lists;
    }

    /**
     * The space-separated list in the test the Schematron runs for a rule:
     * the contains(' A B C ', ...) of the xsl:if just before the rule's
     * failed-assert. Not the failed-assert's own test attribute, which is
     * only reported and for BR-KSA-16 names five codes of the sixty-odd the
     * running test accepts.
     *
     * @return list<string>
     */
    private function listIn(string $xsl, string $rule): array
    {
        $at = strpos($xsl, 'id="'.$rule.'"');
        $this->assertNotFalse($at, "{$rule} is not in the Schematron");
        $window = substr($xsl, max(0, $at - 6000), 6000);
        $if = (string) substr($window, (int) strrpos($window, '<xsl:if test='));
        preg_match("/contains\\(\\s*'\\s*((?:[A-Za-z0-9]+\\s+)+)'/", $if, $found);
        $this->assertNotEmpty($found[1] ?? null, "{$rule}'s code list was not found");

        return array_values(array_filter(preg_split('/\s+/', trim($found[1])) ?: []));
    }

    /**
     * @return list<string>
     */
    private function codes(string $file): array
    {
        $xml = (string) file_get_contents($file);
        preg_match_all('#<gc:Row>.*?<gc:SimpleValue>([^<]*)</gc:SimpleValue>#s', $xml, $found);

        return $found[1];
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function sorted(array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);

        return $codes;
    }
}
