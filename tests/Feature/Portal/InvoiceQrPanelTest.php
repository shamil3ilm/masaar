<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A Saudi invoice's page shows its QR code as a picture a phone can scan,
 * not as the base64 string nobody can.
 */
class InvoiceQrPanelTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * The QR is drawn and captioned in both languages; the payload is only
     * under "Technical details".
     */
    public function test_the_page_draws_the_qr(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        $page = (string) $this->portal()->get(route('portal.invoices.show', $invoice->id))->assertOk()->getContent();
        $outsideDetails = (string) preg_replace('#<details\b.*?</details>#s', '', $page);

        $this->assertMatchesRegularExpression('#data-invoice-qr>\s*(<\?xml[^>]*>\s*)?<svg#', $page);
        $this->assertStringContainsString('رمز الاستجابة السريعة للفاتورة', $page);
        $this->assertStringContainsString(e((string) $invoice->qr_code), $page);
        $this->assertStringNotContainsString(e((string) $invoice->qr_code), $outsideDetails);
        $this->assertStringContainsString(route('portal.invoices.qr', [$invoice->id, 'png']), $page);
    }

    /**
     * The download is the QR as an image, the one a phone reads off the
     * printed copy.
     */
    public function test_the_qr_downloads_as_images(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        $png = $this->portal()->get(route('portal.invoices.qr', [$invoice->id, 'png']))->assertOk();
        $svg = $this->portal()->get(route('portal.invoices.qr', [$invoice->id, 'svg']))->assertOk();

        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringStartsWith(chr(0x89).'PNG', (string) $png->getContent());
        $this->assertStringContainsString('<svg', (string) $svg->getContent());
        $this->assertStringContainsString($invoice->invoice_number.'-qr.svg', (string) $svg->headers->get('Content-Disposition'));
    }

    /**
     * A standard invoice ZATCA has not cleared shows its QR, and gives no
     * copy of it away.
     */
    public function test_an_uncleared_qr_stays_inside(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);
        $invoice->forceFill(['cleared_xml' => null])->saveQuietly();

        $this->portal()->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-invoice-qr', false)
            ->assertDontSee(route('portal.invoices.qr', [$invoice->id, 'png']), false);
        $this->portal()->get(route('portal.invoices.qr', [$invoice->id, 'png']))->assertRedirect(route('portal.invoices.show', $invoice->id));
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id]);
    }
}
