<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * Admin › Branding › Invoice template and Portal › Branding › Invoice
 * template: the platform's default, an organization's and a branch's, each
 * saved, reset and recorded, with a live preview of a sample invoice on
 * every paper and a sample PDF to download.
 */
class InvoiceTemplateScreensTest extends TestCase
{
    use PintInvoices;
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
    }

    public function test_the_platform_sets_the_default(): void
    {
        $owner = $this->admin('owner');

        $this->actingAs($owner)->get(route('admin.invoice-template.edit'))
            ->assertOk()
            ->assertSee('name="layout"', false)
            ->assertSee('data-locked-field="seller_vat_number"', false)
            ->assertSee('VAT Implementing Regulations Art. 53(5)(c)');

        $this->actingAs($owner)->post(route('admin.invoice-template.update'), $this->form(['layout' => 'modern', 'footer_en' => 'With thanks']))
            ->assertRedirect(route('admin.invoice-template.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame(TemplateLayout::Modern, app(InvoiceTemplates::class)->for($this->organization)->layout);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'customization.changed')->where('user_id', $owner->id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'admin.invoice-template.update')->where('user_id', $owner->id)->exists());

        $this->actingAs($owner)->post(route('admin.invoice-template.reset'))->assertRedirect(route('admin.invoice-template.edit'));
        $this->assertSame(TemplateLayout::Classic, app(InvoiceTemplates::class)->for($this->organization)->layout);
    }

    public function test_only_a_configuring_admin_changes_it(): void
    {
        $support = $this->admin('support');

        $this->actingAs($support)->get(route('admin.invoice-template.edit'))->assertOk()->assertDontSee('name="layout"', false);
        $this->actingAs($support)->post(route('admin.invoice-template.update'), $this->form(['layout' => 'modern']))->assertForbidden();

        $this->assertSame([], app(InvoiceTemplates::class)->own(Level::Platform, Scope::platform()));
    }

    public function test_an_organization_and_branch_differ(): void
    {
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id, 'name' => 'Olaya till', 'device_serial' => 'EGS-Olaya',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));
        $admin = $this->member('admin');

        $admin->get(route('portal.invoice-template.edit'))->assertOk()->assertSee('Olaya till')->assertSee('data-template-form', false);
        $admin->post(route('portal.invoice-template.update'), $this->form(['layout' => 'compact']))->assertRedirect(route('portal.invoice-template.edit'));
        $admin->post(route('portal.invoice-template.update'), [...$this->form(['layout' => 'thermal']), 'branch' => $branch->id])
            ->assertRedirect(route('portal.invoice-template.edit', ['branch' => $branch->id]));

        $templates = app(InvoiceTemplates::class);
        $this->assertSame(TemplateLayout::Compact, $templates->for($this->organization)->layout);
        $this->assertSame(TemplateLayout::Thermal, $templates->for($this->organization, (string) $branch->id)->layout);

        $admin->post(route('portal.invoice-template.reset'), ['branch' => $branch->id]);
        $this->assertSame(TemplateLayout::Compact, $templates->for($this->organization, (string) $branch->id)->layout);
    }

    public function test_a_member_cannot_change_it(): void
    {
        $member = $this->member('member');

        $member->get(route('portal.invoice-template.edit'))->assertForbidden();
        $member->post(route('portal.invoice-template.update'), $this->form(['layout' => 'modern']))->assertForbidden();
        $member->get(route('portal.invoice-template.preview'))->assertForbidden();
    }

    public function test_a_bad_iban_is_shown(): void
    {
        $this->member('admin')->from(route('portal.invoice-template.edit'))
            ->post(route('portal.invoice-template.update'), $this->form(['bank_iban' => 'SA0380000000608010167518', 'accent_custom' => '1', 'accent_color' => '#FDE047']))
            ->assertRedirect(route('portal.invoice-template.edit'))
            ->assertSessionHasErrors(['bank_iban', 'accent']);
    }

    /**
     * The preview is the print view of a sample invoice with the form's
     * values, saved or not, on each paper; the sample PDF is its PDF,
     * neither sealed nor carrying an XML.
     */
    public function test_the_preview_follows_the_form(): void
    {
        $admin = $this->member('admin');

        foreach (PaperSize::cases() as $paper) {
            $html = (string) $admin->get(route('portal.invoice-template.preview', [...$this->form(['layout' => 'modern', 'header_en' => 'Preview header']), 'paper' => $paper->value]))
                ->assertOk()->getContent();

            $this->assertStringContainsString('SAMPLE-0001', $html, $paper->value);
            $this->assertStringContainsString('Preview header', $html, $paper->value);
            $this->assertSame(! $paper->isRoll(), str_contains($html, 'tpl-modern'), $paper->value);
            $this->assertStringNotContainsString('Change paper', $html);
        }

        $pdf = $admin->get(route('portal.invoice-template.sample', [...$this->form(['layout' => 'compact']), 'paper' => 'a5']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $bytes = (string) $pdf->getContent();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringNotContainsString('/ETSI.CAdES.detached', $bytes);
        $this->assertStringNotContainsString('/EmbeddedFile', $bytes);

        // Nothing was saved.
        $this->assertSame([], app(InvoiceTemplates::class)->own(Level::Organization, Scope::organization((string) $this->organization->id)));
    }

    public function test_the_admin_previews_a_sample_seller(): void
    {
        $this->actingAs($this->admin('owner'))->get(route('admin.invoice-template.preview', ['paper' => '80mm']))
            ->assertOk()->assertSee('SAMPLE-0001')->assertSee('399999999900003');
        $this->actingAs($this->admin('owner'))->get(route('admin.invoice-template.sample'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /** A country that prints no copy says so, and has nothing to preview. */
    public function test_a_country_without_print_says_so(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $user = User::factory()->create();
        $user->organizations()->attach($gulf->id, ['role' => 'admin', 'status' => 'active']);
        $portal = fn () => $this->actingAs($user)->withSession(['portal_org_id' => $gulf->id]);

        $portal()->get(route('portal.invoice-template.edit'))->assertOk()
            ->assertSee(__('Invoices in your country reach the buyer as e-invoices, so no printed copy is handed over and this template is not used yet. It is kept for when one is.'))
            ->assertDontSee('data-template-preview', false);
        $portal()->get(route('portal.invoice-template.sample'))->assertNotFound();
    }

    /** @param  array<string, mixed>  $values */
    private function form(array $values): array
    {
        return [
            'layout' => 'classic', 'logo_position' => 'start', 'logo_size' => 'medium',
            'accent_custom' => '0', 'accent_color' => '#0F766E', 'language' => '',
            'header_en' => '', 'header_ar' => '', 'footer_en' => '', 'footer_ar' => '', 'terms_en' => '', 'terms_ar' => '',
            'bank_name' => '', 'bank_account_name' => '', 'bank_iban' => '', 'bank_swift' => '',
            'column_unit' => '1', 'column_discount' => '0', 'column_vat_rate' => '0', 'column_item_code' => '0', 'business_qr' => '1',
            ...$values,
        ];
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }

    private function member(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->organization->id]);
    }
}
