<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\InvoiceLink;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A link that opens one issued invoice without signing in: unguessable,
 * revocable, rate limited, kept out of search engines, and its own
 * organization's invoice only.
 */
class InvoiceLinkTest extends TestCase
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
     * Anyone holding the link sees the invoice and downloads its PDF,
     * signed in or not, and nothing tells a search engine to keep it.
     */
    public function test_the_link_opens_the_invoice(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);
        $this->portal()->post(route('portal.qr-codes.links.store'), ['invoice_number' => $invoice->invoice_number])->assertSessionHas('success');
        $link = app(InvoiceLinks::class)->active((string) $this->organization->id)->sole();

        $this->assertSame(43, strlen($link->token));
        $this->assertSame(hash('sha256', $link->token), $link->token_hash);
        $this->assertStringNotContainsString($link->token, (string) InvoiceLink::withoutTenantScope(fn () => InvoiceLink::query()->toBase()->value('token')));

        $this->app['auth']->forgetGuards();
        $this->get(route('invoice-link.show', $link->token))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee($invoice->invoice_number)
            ->assertSee($this->organization->name_ar);
        $pdf = $this->get(route('invoice-link.pdf', $link->token))->assertOk();

        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        $this->assertSame(2, $link->fresh()->open_count);
    }

    /** A revoked link and a made-up one are the same 404. */
    public function test_a_revoked_link_opens_nothing(): void
    {
        $link = app(InvoiceLinks::class)->create($this->sampleInvoice($this->organization, 'simplified', 1));

        $this->portal()->post(route('portal.qr-codes.links.revoke', $link->id))->assertSessionHas('success');

        $this->get(route('invoice-link.show', $link->token))->assertNotFound();
        $this->get(route('invoice-link.show', str_repeat('A', 43)))->assertNotFound();
        $this->get('/i/short')->assertNotFound();
    }

    /** Guessing is slowed: thirty tries a minute from one address. */
    public function test_opening_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('invoice-link.show', str_repeat('B', 43)))->assertNotFound();
        }

        $this->get(route('invoice-link.show', str_repeat('B', 43)))->assertTooManyRequests();
    }

    /**
     * Only an invoice that may be handed over gets a link, and once it may
     * not - here, a standard invoice ZATCA has not cleared - its link stops.
     */
    public function test_an_uncleared_invoice_gets_none(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 1, state: ['cleared_xml' => null]);
        $link = app(InvoiceLinks::class)->create($invoice);

        $this->portal()->post(route('portal.qr-codes.links.store'), ['invoice_number' => $invoice->invoice_number])
            ->assertSessionHasErrors('invoice_number', null, 'link');
        $this->get(route('invoice-link.show', $link->token))->assertNotFound();
    }

    /**
     * Another organization can neither see nor revoke a link, and a link
     * names its own organization's invoice only.
     */
    public function test_links_stay_in_their_organization(): void
    {
        $link = app(InvoiceLinks::class)->create($this->sampleInvoice($this->organization, 'simplified', 1));
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])->post(route('portal.qr-codes.links.revoke', $link->id));
        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.qr-codes.links.download', [$link->id, 'png']))->assertNotFound();

        $this->assertNull($link->fresh()->revoked_at);
        $link->forceFill(['org_id' => $rival->id])->saveQuietly();
        $this->get(route('invoice-link.show', $link->token))->assertNotFound();
    }

    /**
     * Chosen for a paper, each invoice printed on it carries its own link -
     * one per invoice, however often it is printed.
     */
    public function test_printing_gives_one_link(): void
    {
        app(InvoiceExtraQr::class)->setChoices($this->organization, [PaperSize::Roll58->value => InvoiceExtraQr::INVOICE_LINK]);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);

        for ($i = 0; $i < 2; $i++) {
            $this->portal()->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => '58mm']))
                ->assertOk()
                ->assertSee('View this invoice online');
        }

        $this->assertCount(1, app(InvoiceLinks::class)->active((string) $this->organization->id, (string) $invoice->id));
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id]);
    }
}
