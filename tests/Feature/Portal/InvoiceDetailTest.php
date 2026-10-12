<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * One invoice, in full.
 *
 * Asserted on real values rather than assertOk(), deliberately.
 * AttributeReadTest scans app/ only, so a Blade view that reads an attribute
 * no column answers renders an empty string and the page still returns 200 -
 * a totals panel full of blanks would pass a status-code assertion.
 */
class InvoiceDetailTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, [
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    public function test_the_detail_shows_stored_totals(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', [
            'subtotal' => '200.00',
            'tax_amount' => '30.00',
            'total' => '230.00',
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('200.00 SAR')
            ->assertSee('30.00 SAR')
            ->assertSee('230.00 SAR'));
    }

    public function test_every_line_is_listed(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1');

        foreach (['Widget', 'Sprocket'] as $description) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'quantity' => '1.000',
                'unit_price' => '100.00',
                'tax_rate' => '15.00',
                'tax_amount' => '15.00',
                'tax_category' => 'S',
                'line_total' => '100.00',
            ]);
        }

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('Widget')
            ->assertSee('Sprocket'));
    }

    /**
     * A draft has no counter, hash or QR. Saying so beats rendering empty
     * rows that look like something failed.
     */
    public function test_a_draft_shows_no_hash(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', ['status' => 'draft']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('still a draft'));
    }

    public function test_an_issued_invoice_shows_its_hash(): void
    {
        $invoice = $this->makeInvoice($this->acme, 'ACME-1', [
            'status' => 'issued',
            'hash' => 'cHJldGVuZC1oYXNo',
            'icv' => 7,
        ]);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('cHJldGVuZC1oYXNo')
            ->assertSee('7'));
    }

    /**
     * Another tenant's invoice and one that does not exist answer the same,
     * or a differing 404 confirms the id belongs to someone.
     */
    public function test_another_tenant_invoice_is_missing(): void
    {
        $rivals = $this->makeInvoice($this->rival, 'RIVAL-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.$rivals->id)
            ->assertNotFound());
    }

    public function test_an_unknown_invoice_is_missing(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices/'.Str::uuid())
            ->assertNotFound());
    }
}
