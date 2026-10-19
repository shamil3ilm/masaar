<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public site says plainly what each country's invoices go through and
 * which standards they are built to, in the module's own words, and claims
 * nothing Masaar does not hold.
 */
class PublicStandardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_names_zatca_phase_two(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('ZATCA Fatoora Phase 2: tax invoices cleared and simplified invoices reported, as ZATCA requires.');
    }

    public function test_home_names_peppol_through_a_provider(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Peppol PINT AE e-invoices, exchanged through an accredited service provider.');
    }

    public function test_the_standards_line_lists_all_four(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Standards we follow', 'ZATCA XML Implementation Standard', 'UBL 2.1', 'PDF/A-3', 'Peppol PINT AE']);
    }

    /** Read from the registry's switch, not written into the page. */
    public function test_switched_off_country_is_coming_soon(): void
    {
        app(JurisdictionAvailability::class)->set('AE', false);

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('United Arab Emirates <span class="font-normal">(coming soon)</span>', $html);
        $this->assertStringNotContainsString('Saudi Arabia <span class="font-normal">', $html);

        app(JurisdictionAvailability::class)->set('AE', true);

        $this->assertStringNotContainsString('(coming soon)', (string) $this->get('/')->getContent());
    }

    public function test_it_claims_no_certification(): void
    {
        $html = strtolower((string) $this->get('/')->getContent());

        foreach (['certified', 'certification', 'accredited by', 'approved by zatca', 'masaar is accredited'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }

    public function test_it_reads_in_arabic(): void
    {
        $this->post('/locale', ['locale' => 'ar']);

        $this->get('/')
            ->assertOk()
            ->assertSee('المرحلة الثانية من فاتورة لدى هيئة الزكاة والضريبة والجمارك')
            ->assertSee('تُتبادَل عبر مزوّد خدمة معتمد')
            ->assertSee('المعايير التي نتّبعها');
    }
}
