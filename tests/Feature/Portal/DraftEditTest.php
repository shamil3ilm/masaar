<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A draft is changed or thrown away in the portal, and only while it is a
 * draft: once issued it has a counter and a hash, and is corrected by a note.
 *
 * The form also carries a token, so saving the same form twice - Back, then
 * Save as draft again - returns the draft it already made instead of a copy.
 */
class DraftEditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'invoice-form-token';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->member = $this->memberAs('member');
    }

    public function test_a_draft_opens_for_editing(): void
    {
        $draft = $this->draft();

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get(route('portal.invoices.edit', $draft->id))
            ->assertOk()
            ->assertSee('Edit draft')
            ->assertSee('Walk-in customer')
            ->assertSee('value="'.$draft->id.'"', false)
            ->assertSee('أداة'));
    }

    public function test_an_edited_draft_keeps_its_id(): void
    {
        $draft = $this->draft();

        $this->submit($this->simplified([
            'replaces' => $draft->id,
            'buyer_name' => 'Fixed name',
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '2', 'unit_price' => '100', 'tax_rate' => '15', 'unit_code' => 'PCE'],
                ['description' => 'Gadget', 'description_ar' => 'جهاز', 'quantity' => '1', 'unit_price' => '10', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ]))->assertRedirect(route('portal.invoices.show', $draft->id))->assertSessionHas('success');

        $fresh = $this->invoices()->sole();
        $this->assertSame([$draft->id, 'Fixed name', '241.50', 2], [$fresh->id, $fresh->buyer_name, (string) $fresh->total, $fresh->lines()->count()]);
        $this->assertTrue(AuditLog::query()->where('action', 'Invoice.updated')->where('entity_id', $draft->id)->exists());
    }

    public function test_an_issued_invoice_is_not_edited(): void
    {
        $draft = $this->draft();
        $this->markIssued($draft);

        $this->asRequest(fn () => $this->actingAs($this->member)->get(route('portal.invoices.edit', $draft->id)))
            ->assertRedirect(route('portal.invoices.show', $draft->id))
            ->assertSessionHas('error');

        $this->submit($this->simplified(['replaces' => $draft->id, 'buyer_name' => 'Changed']))->assertSessionHas('error');

        $this->assertSame('Walk-in customer', $this->invoices()->sole()->buyer_name);
    }

    public function test_a_draft_is_deleted(): void
    {
        $draft = $this->draft();

        $this->asRequest(fn () => $this->actingAs($this->member)->withSession(['_token' => self::TOKEN])->post(route('portal.invoices.delete', $draft->id), ['_token' => self::TOKEN]))
            ->assertRedirect(route('portal.invoices.index'))
            ->assertSessionHas('success');

        $this->assertSame(0, $this->invoices()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'Invoice.deleted')->where('entity_id', $draft->id)->exists());
    }

    public function test_an_issued_invoice_is_not_deleted(): void
    {
        $draft = $this->draft();
        $this->markIssued($draft);

        $this->asRequest(fn () => $this->actingAs($this->member)->withSession(['_token' => self::TOKEN])->post(route('portal.invoices.delete', $draft->id), ['_token' => self::TOKEN]))
            ->assertRedirect(route('portal.invoices.show', $draft->id))
            ->assertSessionHas('error');

        $this->assertSame(1, $this->invoices()->count());
    }

    public function test_a_viewer_cannot_change_drafts(): void
    {
        $draft = $this->draft();
        $viewer = $this->memberAs('viewer');

        $this->asRequest(fn () => $this->actingAs($viewer)->get(route('portal.invoices.edit', $draft->id)))->assertForbidden();
        $this->asRequest(fn () => $this->actingAs($viewer)->withSession(['_token' => self::TOKEN])->post(route('portal.invoices.delete', $draft->id), ['_token' => self::TOKEN]))->assertForbidden();
        $this->asRequest(fn () => $this->actingAs($viewer)->get(route('portal.invoices.show', $draft->id)))
            ->assertOk()
            ->assertDontSee(route('portal.invoices.edit', $draft->id))
            ->assertDontSee(route('portal.invoices.delete', $draft->id));

        $this->assertSame(1, $this->invoices()->count());
    }

    public function test_the_draft_page_offers_both(): void
    {
        $draft = $this->draft();

        $this->asRequest(fn () => $this->actingAs($this->member)->get(route('portal.invoices.show', $draft->id)))
            ->assertOk()
            ->assertSee(route('portal.invoices.edit', $draft->id))
            ->assertSee(route('portal.invoices.delete', $draft->id))
            ->assertSee('data-confirm-tone="danger"', false);

        $this->markIssued($draft);

        $this->asRequest(fn () => $this->actingAs($this->member)->get(route('portal.invoices.show', $draft->id)))
            ->assertOk()
            ->assertDontSee(route('portal.invoices.edit', $draft->id))
            ->assertDontSee(route('portal.invoices.delete', $draft->id));
    }

    public function test_a_resubmitted_form_makes_one_draft(): void
    {
        $first = $this->submit($this->simplified(['form_token' => 'f0c5a1d2-0000-4000-8000-000000000001']));
        $draft = $this->invoices()->sole();
        $first->assertRedirect(route('portal.invoices.show', $draft->id));

        $this->submit($this->simplified(['form_token' => 'f0c5a1d2-0000-4000-8000-000000000001']))
            ->assertRedirect(route('portal.invoices.show', $draft->id))
            ->assertSessionHas('success');

        $this->assertSame(1, $this->invoices()->count());
    }

    public function test_the_form_carries_a_token(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get(route('portal.invoices.create'))
            ->assertOk()
            ->assertSee('name="form_token"', false));
    }

    private function draft(): Invoice
    {
        $this->submit($this->simplified())->assertSessionHas('success');

        return $this->invoices()->sole();
    }

    private function markIssued(Invoice $invoice): void
    {
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->update(['status' => InvoiceStatus::Issued->value, 'hash' => 'issued-hash']));
    }

    /** @return Builder<Invoice> */
    private function invoices(): Builder
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->acme->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function simplified(array $overrides = []): array
    {
        return array_merge([
            '_token' => self::TOKEN,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'buyer_name_ar' => 'عميل نقدي',
            'lines' => [
                ['description' => 'Widget', 'description_ar' => 'أداة', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15', 'unit_code' => 'PCE'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices', $payload));
    }

    private function memberAs(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
