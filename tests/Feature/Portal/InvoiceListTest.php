<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The taxpayer can see their own invoices.
 *
 * Until this page existed there was no invoice surface in the browser at all:
 * an invoice created by the API, the ERP pipeline or a partner was invisible
 * to the taxpayer who owned it.
 *
 * Every assertion runs inside asRequest(). TenantScope stands down in console
 * context and PHPUnit is console, so a plain $this->get() exercises the
 * unscoped path - which is exactly the path that cannot show a leak.
 */
class InvoiceListTest extends TestCase
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

    public function test_the_list_shows_own_invoices(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices')
            ->assertOk()
            ->assertSee('ACME-1'));
    }

    /**
     * The one that matters. A leak here hands one taxpayer another's turnover.
     */
    public function test_the_list_hides_other_tenants(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1');
        $this->makeInvoice($this->rival, 'RIVAL-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices')
            ->assertOk()
            ->assertSee('ACME-1')
            ->assertDontSee('RIVAL-1'));
    }

    public function test_status_narrows_the_list(): void
    {
        $this->makeInvoice($this->acme, 'DRAFT-1', ['status' => 'draft']);
        $this->makeInvoice($this->acme, 'ISSUED-1', ['status' => 'issued']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices?status=issued')
            ->assertOk()
            ->assertSee('ISSUED-1')
            ->assertDontSee('DRAFT-1'));
    }

    public function test_search_matches_invoice_number(): void
    {
        $this->makeInvoice($this->acme, 'FIND-ME');
        $this->makeInvoice($this->acme, 'OTHER-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices?q=FIND')
            ->assertOk()
            ->assertSee('FIND-ME')
            ->assertDontSee('OTHER-1'));
    }

    /**
     * Status and search together must AND, not OR. Ungrouped `orWhere`
     * clauses would widen the result back out to every status.
     */
    public function test_search_and_status_combine(): void
    {
        $this->makeInvoice($this->acme, 'KEEP-1', ['status' => 'issued']);
        $this->makeInvoice($this->acme, 'KEEP-2', ['status' => 'draft']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices?q=KEEP&status=issued')
            ->assertOk()
            ->assertSee('KEEP-1')
            ->assertDontSee('KEEP-2'));
    }

    /**
     * A malformed query must narrow nothing rather than be passed to the
     * query builder, where an array is a type error and not an empty result.
     */
    public function test_an_array_filter_is_ignored(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices?status[]=issued')
            ->assertOk()
            ->assertSee('ACME-1'));
    }

    /**
     * Not every taxpayer bills in riyals, and a UAE organization bills in
     * none. The list rendered a hardcoded 'SAR' on the submissions page; this
     * holds the invoice pages to the invoice's own currency.
     */
    public function test_money_uses_invoice_currency(): void
    {
        $this->makeInvoice($this->acme, 'AED-1', ['currency' => 'AED', 'total' => '210.00']);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/invoices')
            ->assertOk()
            ->assertSee('210.00 AED'));
    }
}
