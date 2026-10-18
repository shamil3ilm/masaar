<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\BusinessQrCode;
use App\Domains\Invoice\Services\BusinessQrCodes;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Organization\Models\Organization;
use App\Support\QrCodeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * An organization's own QR codes: a contact card, payment details, a link -
 * and the one it chooses to print on its invoices, beside the country's.
 */
class BusinessQrCodesTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    /** A valid IBAN: its mod-97 check is 1. */
    private const IBAN = 'SA0380000000608010167519';

    private Organization $organization;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * A vCard every phone offers to save, its text escaped as RFC 6350
     * asks, Arabic included.
     */
    public function test_a_contact_card_is_a_vcard(): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, BusinessQrCode::CONTACT, [
            'name' => 'أفق الخليج, Riyadh; KSA',
            'phone' => '+966 11 000 0000',
            'email' => 'sales@example.test',
            'website' => 'https://example.test',
            'address' => 'King Fahd Road, Riyadh',
        ]);

        $this->assertSame(implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:أفق الخليج\, Riyadh\; KSA',
            'ORG:أفق الخليج\, Riyadh\; KSA',
            'TEL;TYPE=WORK,VOICE:+966 11 000 0000',
            'EMAIL;TYPE=INTERNET:sales@example.test',
            'URL:https://example.test',
            'ADR;TYPE=WORK:;;King Fahd Road\, Riyadh;;;;',
            'END:VCARD',
        ]), app(BusinessQrCodes::class)->payload($code));
    }

    /**
     * Payment details as plain text; on an invoice, its amount due and
     * number fill what the code leaves out.
     */
    public function test_payment_details_take_the_invoice(): void
    {
        $codes = app(BusinessQrCodes::class);
        $code = $codes->create((string) $this->organization->id, BusinessQrCode::PAYMENT, ['bank' => 'Riyad Bank', 'iban' => 'sa03 8000 0000 6080 1016 7519']);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);

        $this->assertSame("Bank / البنك: Riyad Bank\nIBAN / الآيبان: ".self::IBAN, $codes->payload($code));
        $this->assertSame(
            "Bank / البنك: Riyad Bank\nIBAN / الآيبان: ".self::IBAN."\nAmount / المبلغ: ".number_format((float) $invoice->total, 2, '.', '')." SAR\nReference / المرجع: ".$invoice->invoice_number,
            $codes->payload($code, $invoice),
        );
    }

    /**
     * Administrators make the codes; anyone in the organization downloads
     * them; another organization's are not found.
     */
    public function test_admins_make_and_members_download(): void
    {
        $this->portal($this->member)->post(route('portal.qr-codes.store'), ['kind' => 'link', 'url' => 'https://maps.example.test/x'])->assertForbidden();
        $this->portal($this->admin)->post(route('portal.qr-codes.store'), ['kind' => 'payment', 'bank' => 'Riyad Bank', 'iban' => 'SA0380000000608010167518'])
            ->assertSessionHasErrors('iban', null, 'payment');
        $this->portal($this->admin)->post(route('portal.qr-codes.store'), ['kind' => 'link', 'label' => 'Find us', 'url' => 'https://maps.example.test/x'])
            ->assertSessionHas('success');

        $code = app(BusinessQrCodes::class)->all((string) $this->organization->id)->sole();
        $png = $this->portal($this->member)->get(route('portal.qr-codes.download', [$code->id, 'png']))->assertOk();
        $this->portal($this->member)->get(route('portal.qr-codes.index'))->assertOk()->assertSee('Find us')->assertSee('data-qr-code', false);

        $this->assertStringStartsWith(chr(0x89).'PNG', (string) $png->getContent());
        $this->assertSame('Find us', $code->label);

        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);
        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.qr-codes.download', [$code->id, 'svg']))->assertNotFound();
    }

    /**
     * The chosen code prints apart from the country's QR, labelled, on the
     * paper it was chosen for and no other; the country's QR is unchanged.
     */
    public function test_the_chosen_code_prints_apart(): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, BusinessQrCode::LINK, ['label' => 'Find us', 'url' => 'https://maps.example.test/x']);
        $this->portal($this->admin)->post(route('portal.qr-codes.invoices'), ['papers' => ['80mm' => $code->id, 'a4' => 'not-a-code']])->assertSessionHas('success');
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);
        $zatca = app(QrCodeRenderer::class)->svg((string) $invoice->qr_code);

        $this->assertSame(['80mm' => $code->id], app(InvoiceExtraQr::class)->choices($this->organization->fresh()));

        $this->portal($this->member)->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => '80mm']))
            ->assertOk()
            ->assertSee('data-print-extra-qr', false)
            ->assertSee('Find us')
            ->assertSee($zatca, false);
        $this->portal($this->member)->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => 'a4']))
            ->assertOk()
            ->assertDontSee('data-print-extra-qr', false);
    }

    /**
     * The invoice page shows the code its invoices carry beside the ZATCA
     * QR, separately labelled.
     */
    public function test_the_invoice_page_shows_it(): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, BusinessQrCode::LINK, ['label' => 'Find us', 'url' => 'https://maps.example.test/x']);
        app(InvoiceExtraQr::class)->setChoices($this->organization, [PaperSize::A4->value => $code->id]);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);

        $this->portal($this->member)->get(route('portal.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('data-invoice-qr', false)
            ->assertSee('data-business-qr', false)
            ->assertSee('Find us');
    }

    /** Deleting a code stops it printing. */
    public function test_a_deleted_code_stops_printing(): void
    {
        $code = app(BusinessQrCodes::class)->create((string) $this->organization->id, BusinessQrCode::LINK, ['url' => 'https://example.test']);
        app(InvoiceExtraQr::class)->setChoices($this->organization, ['58mm' => $code->id]);

        $this->portal($this->admin)->post(route('portal.qr-codes.destroy', $code->id))->assertSessionHas('success');

        $this->assertSame([], app(InvoiceExtraQr::class)->choices($this->organization->fresh()));
        $this->assertNull(app(InvoiceExtraQr::class)->for($this->sampleInvoice($this->organization, 'simplified', 1), $this->organization->fresh(), PaperSize::Roll58));
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->organization->id]);
    }
}
