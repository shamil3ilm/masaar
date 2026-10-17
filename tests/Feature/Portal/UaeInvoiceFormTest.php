<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\Peppol\PintInvoices;
use Tests\TestCase;

/**
 * A UAE organization's "New invoice": its own form, validated by the API's
 * rules and drafted the API's way, so the draft is one UaeInvoiceIssuer
 * issues. A Saudi organization's form is as it was.
 */
class UaeInvoiceFormTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private const TOKEN = 'uae-invoice-form-token';

    private Organization $gulf;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
        $this->admin = $this->member($this->gulf);
    }

    public function test_uae_organization_gets_uae_form(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->gulf->id])
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee(route('portal.invoices.uae.store'), false)
            ->assertSee('name="document_category"', false)
            ->assertSee('name="buyer_endpoint_choice"', false)
            ->assertSee('name="flags[]"', false)
            ->assertSee('lines[0][item_name]', false)
            ->assertSee('lines[0][tax_category]', false)
            ->assertDontSee('value="debit_note"', false)
            ->assertDontSee('value="simplified"', false));
    }

    public function test_saudi_form_is_unchanged(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme))
            ->get('/portal/invoices/create')
            ->assertOk()
            ->assertSee(route('portal.invoices.store'), false)
            ->assertSee('value="debit_note"', false)
            ->assertSee('value="simplified"', false)
            ->assertSee('lines[0][description_ar]', false)
            ->assertDontSee('name="buyer_endpoint_choice"', false));
    }

    /** Drafted from the form, issued from the invoice page, queued to send. */
    public function test_form_draft_is_issued_and_queued(): void
    {
        $this->submit($this->form())->assertSessionHasNoErrors()->assertSessionHas('success');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->where('org_id', $this->gulf->id)->sole());

        $this->assertSame(['draft', 'standard', 'invoice', 'AED'], [$invoice->status->value, $invoice->type->value, $invoice->document_type->value, $invoice->currency]);
        $this->assertSame(['1000.00', '50.00', '1050.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);
        // Sorted: MySQL stores a JSON object's keys in its own order, and
        // nothing reads jurisdiction_data by position.
        $stored = $invoice->jurisdiction_data;
        ksort($stored);
        $this->assertSame([
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'document_category' => 'tax_invoice',
            'transaction_flags' => '00000000',
        ], $stored);
        $this->assertSame(['Consulting', 'S', '5.00'], [$invoice->lines[0]->item_name, $invoice->lines[0]->tax_category, $invoice->lines[0]->tax_rate]);

        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->post("/portal/invoices/{$invoice->id}/issue", ['_token' => self::TOKEN])
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('success'));

        $this->assertSame('issued', $invoice->fresh()->status->value);
        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->where('invoice_id', $invoice->id)->sole());
        $this->assertSame(['out', 'ready'], [$document->direction, $document->status]);
        Queue::assertPushed(SendPeppolDocument::class, 1);
    }

    public function test_ticked_flags_become_the_code(): void
    {
        $this->submit($this->form(['flags' => ['4', '6']]))->assertSessionHasNoErrors();

        $this->assertSame('00001010', $this->drafted()->jurisdiction_data['transaction_flags']);
    }

    /** A reserved endpoint for a deemed supply or an export sets that flag too. */
    public function test_reserved_endpoint_sets_its_flag(): void
    {
        $this->submit($this->form(['buyer_endpoint_choice' => 'export', 'buyer_endpoint' => '']))->assertSessionHasNoErrors();

        $data = $this->drafted()->jurisdiction_data;
        $this->assertSame(['0235:9900000099', '00000001'], [$data['buyer_endpoint'], $data['transaction_flags']]);
    }

    public function test_buyer_not_yet_on_einvoicing(): void
    {
        $this->submit($this->form(['buyer_endpoint_choice' => 'not_yet', 'buyer_endpoint' => '0235:1007654321']))->assertSessionHasNoErrors();

        $data = $this->drafted()->jurisdiction_data;
        $this->assertSame(['0235:9900000098', '00000000'], [$data['buyer_endpoint'], $data['transaction_flags']]);
    }

    /** The API's rules, with the form's input kept for another try. */
    public function test_uae_rules_refuse_saudi_shapes(): void
    {
        $this->submit($this->form(['document_type' => 'debit_note']))
            ->assertRedirect('/portal/invoices/create')->assertSessionHasErrors('document_type')->assertSessionHasInput('buyer_name');
        $this->submit($this->form(['lines' => [$this->formLine(['tax_rate' => '15'])]]))->assertSessionHasErrors('lines.0.tax_rate');
        $this->submit($this->form(['lines' => [$this->formLine(['tax_category' => 'E', 'exempt_code' => 'VATEX-SA-29'])]]))->assertSessionHasErrors('lines.0.exempt_code');

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_tax_credit_note_needs_reference(): void
    {
        $this->submit($this->form(['document_type' => 'credit_note', 'due_date' => '']))
            ->assertSessionHasErrors(['credit_reason', 'billing_ref']);

        $this->submit($this->form(['document_type' => 'credit_note', 'due_date' => '', 'credit_reason' => 'DL8.61.1.A', 'billing_ref' => 'AE-0001']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['credit_note', 'AE-0001', 'tax_credit_note'], [
            $this->drafted()->document_type->value, $this->drafted()->billing_ref, $this->drafted()->jurisdiction_data['document_category'],
        ]);
    }

    /** The Saudi form's route drafts nothing for a UAE organization. */
    public function test_saudi_route_refuses_uae_organization(): void
    {
        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->post('/portal/invoices', [
                '_token' => self::TOKEN,
                'type' => 'standard', 'document_type' => 'invoice', 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
                'buyer_name' => 'Noor', 'buyer_vat_number' => '300000000000003',
                'buyer_address' => ['street' => 'X', 'building_number' => '1234', 'district' => 'Y', 'city' => 'Z', 'postal_code' => '12345', 'country_code' => 'SA'],
                'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15']],
            ])
            ->assertRedirect(route('portal.invoices.create'))
            ->assertSessionHas('error'));

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    /**
     * Switched off, a draft is still saved, as on the Saudi form: issuing it
     * is what the engine refuses.
     */
    public function test_switched_off_still_saves_drafts(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $this->submit($this->form())->assertSessionHas('success');
    }

    public function test_saudi_organization_gets_not_found(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->asRequest(fn () => $this->actingAs($this->member($acme))
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices/uae', [...$this->form(), '_token' => self::TOKEN])
            ->assertNotFound());
    }

    private function drafted(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $this->gulf->id)->latest()->firstOrFail());
    }

    /** @param  array<string, mixed>  $data */
    private function submit(array $data): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->gulf->id, '_token' => self::TOKEN])
            ->from('/portal/invoices/create')
            ->post('/portal/invoices/uae', [...$data, '_token' => self::TOKEN]));
    }

    /**
     * The form as a browser sends it: every untouched field an empty string.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'invoice_number' => '',
            'document_type' => 'invoice',
            'document_category' => 'tax',
            'issue_date' => now()->toDateString(),
            'supply_date' => '',
            'supply_end_date' => '',
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'AED',
            'exchange_rate' => '',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_name_ar' => '',
            'buyer_vat_number' => '100765432100003',
            'buyer_endpoint_choice' => 'own',
            'buyer_endpoint' => '0235:1007654321',
            'buyer_address' => ['street' => 'Khalifa Street', 'building_number' => '', 'city' => 'Abu Dhabi', 'postal_code' => '', 'country_code' => 'AE'],
            'buyer_subdivision' => 'AUH',
            'buyer_legal_registration' => ['type' => '', 'id' => '', 'authority' => ''],
            'beneficiary_id' => '',
            'principal_id' => '',
            'delivery_address' => ['street' => '', 'city' => '', 'subdivision' => '', 'postal_code' => '', 'country_code' => ''],
            'lines' => [$this->formLine()],
            'billing_ref' => '',
            'credit_reason' => '',
            'discount_amount' => '',
            'notes' => '',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function formLine(array $overrides = []): array
    {
        return array_merge([
            'item_name' => 'Consulting',
            'description' => 'Consulting services, October',
            'quantity' => '2',
            'unit_code' => 'DAY',
            'unit_price' => '500',
            'tax_category' => 'S',
            'tax_rate' => '5',
            'exempt_code' => '',
            'goods_type' => '',
            'item_id' => '',
        ], $overrides);
    }

    private function member(Organization $organization, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
