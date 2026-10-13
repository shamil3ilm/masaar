<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Support\UnitCode;
use PHPUnit\Framework\TestCase;

/**
 * A quantity is counted in something both authorities recognise.
 *
 * ZATCA's data dictionary says units "are recommended to be from UN/ECE
 * Recommendation 20, Revision 16 (2020)". Peppol BIS Billing 3.0 requires
 * it, and adds Recommendation 21's packaging codes with an X prefix so they
 * cannot collide with Recommendation 20 values.
 *
 * Four codes this application used are in neither list, and the worst of
 * them was the default:
 *
 *   PCE  on every line that named no unit, written into InvoiceDrafter,
 *        DocumentBuilder, XmlBuilder twice, FtaXmlBuilder, FtaService and
 *        the compliance sample set. Recommendation 20's piece is H87.
 *   BX   is Recommendation 21's box, which Peppol writes XBX.
 *   PK   its package, XPK.
 *   CT   its carton, XCT.
 *
 * None of this was caught because ZATCA's schematron does not check the code
 * against a list - the only EN16931 rule on unitCode is that the attribute is
 * at most 127 characters - so every invoice filed so far passed with a unit
 * code ZATCA itself recommends against, and would not have passed on the
 * UAE's Peppol-based path.
 *
 * The codes below were read off the published list rather than remembered.
 * The point of this test is that the next person adding one has to do the
 * same.
 */
class UnitCodeTest extends TestCase
{
    /**
     * The four that are not in either published list.
     *
     * Accepted on input and translated, never emitted.
     */
    public function test_a_legacy_code_is_translated(): void
    {
        $this->assertSame('H87', UnitCode::normalise('PCE'));
        $this->assertSame('XBX', UnitCode::normalise('BX'));
        $this->assertSame('XPK', UnitCode::normalise('PK'));
        $this->assertSame('XCT', UnitCode::normalise('CT'));
    }

    /**
     * And none of them is offered.
     *
     * accepted() is wider than codes() on purpose - an ERP already sending
     * PCE keeps working - but nothing should ever write one.
     */
    public function test_a_legacy_code_is_never_offered(): void
    {
        foreach (array_keys(UnitCode::LEGACY) as $code) {
            $this->assertNotContains($code, UnitCode::codes(),
                $code.' is not in UN/ECE Recommendation 20 and must not be offered.');

            $this->assertContains($code, UnitCode::accepted(),
                $code.' was sent by this platform for a long time and must still be accepted.');
        }
    }

    /**
     * A line that names no unit gets one that exists.
     */
    public function test_the_default_is_a_real_code(): void
    {
        $this->assertSame(UnitCode::DEFAULT, UnitCode::normalise(null));
        $this->assertSame(UnitCode::DEFAULT, UnitCode::normalise(''));
        $this->assertContains(UnitCode::DEFAULT, UnitCode::codes());
    }

    /**
     * A packaging code is written the way Peppol writes it.
     *
     * Recommendation 21's codes are two characters, and Peppol prefixes each
     * with X so it cannot be mistaken for a Recommendation 20 value. Writing
     * one bare is the mistake this codebase already made four times.
     */
    public function test_packaging_codes_carry_their_prefix(): void
    {
        foreach (array_keys(UnitCode::GROUPS['Packaging']) as $code) {
            $this->assertStringStartsWith('X', $code,
                $code.' is a Recommendation 21 packaging code and Peppol writes it with an X prefix.');

            $this->assertSame(3, strlen($code), $code.' should be X plus the two-character code.');
        }
    }

    /**
     * An unknown code is passed through, not quietly made into a piece.
     *
     * Validation is where a bad code is refused. Translating one here would
     * turn a rejected invoice into an accepted invoice for the wrong
     * quantity, which nobody would see.
     */
    public function test_an_unknown_code_is_untouched(): void
    {
        $this->assertSame('ZZZ', UnitCode::normalise('ZZZ'));
    }

    /**
     * Every code is three characters or fewer and upper case.
     *
     * Both lists are; a lower-case code is a typo that would reach the
     * authority as a different unit.
     */
    public function test_every_code_is_well_formed(): void
    {
        foreach (UnitCode::codes() as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,3}$/', $code, $code.' is not shaped like a code.');
        }
    }

    /**
     * The groups are for reading, so no code may sit in two of them.
     */
    public function test_no_code_is_in_two_groups(): void
    {
        $flat = [];

        foreach (UnitCode::GROUPS as $codes) {
            $flat = [...$flat, ...array_keys($codes)];
        }

        $this->assertSame(array_unique($flat), $flat, 'A code appears in more than one group.');
        $this->assertSame(count($flat), count(UnitCode::all()), 'A label was lost when the groups were flattened.');
    }
}
