<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * An invoice template: the platform's default, an organization's over it,
 * a branch's over that - each level keeping only what it changes.
 */
class InvoiceTemplatesTest extends TestCase
{
    use PintInvoices;
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private InvoiceTemplates $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->admin = User::factory()->create();
        $this->templates = app(InvoiceTemplates::class);
    }

    public function test_it_ships_as_todays_classic(): void
    {
        $template = $this->templates->for($this->organization);

        $this->assertSame(TemplateLayout::Classic, $template->layout);
        $this->assertSame('start', $template->logoPosition);
        $this->assertSame('medium', $template->logoSize);
        $this->assertTrue($template->columns['unit']);
        $this->assertFalse($template->columns['item_code']);
        $this->assertTrue($template->businessQr);
        $this->assertTrue($template->arabicFirst);
        $this->assertTrue($template->bank->isEmpty());
        $this->assertSame('', $template->header->en);
    }

    public function test_the_accent_follows_the_theme(): void
    {
        app(ThemeService::class)->save((string) $this->organization->id, ThemeDefinition::fromArray(['colors' => ['primary' => '#1E3A8A']]));

        $this->assertSame('#1E3A8A', $this->templates->for($this->organization)->accent);
    }

    public function test_each_level_keeps_its_changes(): void
    {
        $branch = $this->branch($this->organization, 'Olaya till');
        $organizationId = (string) $this->organization->id;

        $this->templates->save(Level::Platform, Scope::platform(), ['layout' => 'modern', 'footer_en' => 'Thank you'], null, $this->admin->id);
        $this->templates->save(Level::Organization, Scope::organization($organizationId), ['layout' => 'compact', 'footer_en' => 'Thank you'], $this->organization, $this->admin->id);
        $this->templates->save(Level::Branch, Scope::branch($organizationId, (string) $branch->id), ['layout' => 'thermal'], $this->organization, $this->admin->id);

        $this->assertSame(TemplateLayout::Compact, $this->templates->for($this->organization)->layout);
        $this->assertSame(TemplateLayout::Thermal, $this->templates->for($this->organization, (string) $branch->id)->layout);
        $this->assertSame('Thank you', $this->templates->for($this->organization, (string) $branch->id)->footer->en);
        // The organization kept only what differs from the platform's.
        $this->assertSame(['layout' => 'compact'], $this->templates->own(Level::Organization, Scope::organization($organizationId)));

        $this->templates->save(Level::Platform, Scope::platform(), ['layout' => 'modern', 'footer_en' => 'With thanks'], null, $this->admin->id);
        $this->assertSame('With thanks', $this->templates->for($this->organization, (string) $branch->id)->footer->en);
    }

    public function test_reset_returns_to_the_inherited(): void
    {
        $scope = Scope::organization((string) $this->organization->id);
        $this->templates->save(Level::Organization, $scope, ['layout' => 'modern', 'column_item_code' => '1'], $this->organization, $this->admin->id);
        $this->templates->reset(Level::Organization, $scope, $this->admin->id);

        $this->assertSame([], $this->templates->own(Level::Organization, $scope));
        $this->assertSame(TemplateLayout::Classic, $this->templates->for($this->organization)->layout);
        $this->assertFalse($this->templates->for($this->organization)->columns['item_code']);
    }

    public function test_another_organizations_branch_is_refused(): void
    {
        $other = $this->sampleOrganization();
        $foreign = $this->branch($other, 'Foreign');

        $this->expectException(ValidationException::class);

        $this->templates->save(Level::Branch, Scope::branch((string) $this->organization->id, (string) $foreign->id), ['layout' => 'modern'], $this->organization, $this->admin->id);
    }

    public function test_bank_details_are_checked(): void
    {
        $scope = Scope::organization((string) $this->organization->id);

        foreach ([
            'bank_iban' => 'SA0380000000608010167518',
            'bank_swift' => 'RIBL-SA',
            'layout' => 'poster',
            'accent' => 'red',
        ] as $field => $bad) {
            try {
                $this->templates->save(Level::Organization, $scope, [$field => $bad], $this->organization, $this->admin->id);
                $this->fail($field.' accepted '.$bad);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->templates->save(Level::Organization, $scope, [
            'bank_name' => 'Riyad Bank',
            'bank_iban' => 'sa03 8000 0000 6080 1016 7519',
            'bank_account_name' => 'Gulf Horizon Trading',
            'bank_swift' => 'riblsari',
        ], $this->organization, $this->admin->id);

        $bank = $this->templates->for($this->organization)->bank;
        $this->assertSame('SA0380000000608010167519', $bank->iban);
        $this->assertSame('RIBLSARI', $bank->swift);
        $this->assertSame('SA03 8000 0000 6080 1016 7519', $bank->formattedIban());
    }

    public function test_a_country_keeps_its_languages(): void
    {
        $scope = Scope::organization((string) $this->organization->id);

        // An invoice here is in Arabic, a translation beside it allowed.
        $this->assertSame(['arabic_first', 'english_first'], array_keys($this->templates->languages($this->organization)));

        try {
            $this->templates->save(Level::Organization, $scope, ['language' => 'english'], $this->organization, $this->admin->id);
            $this->fail('English alone was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('language', $e->errors());
        }

        $this->templates->save(Level::Organization, $scope, ['language' => 'english_first'], $this->organization, $this->admin->id);
        $this->assertFalse($this->templates->for($this->organization)->arabicFirst);

        // English alone set by the platform still prints both here.
        $this->templates->reset(Level::Organization, $scope, $this->admin->id);
        $this->templates->save(Level::Platform, Scope::platform(), ['language' => 'english'], null, $this->admin->id);
        $this->assertSame('english_first', $this->templates->for($this->organization)->language);

        $gulf = $this->uaeOrganization();
        // English, as the guidelines' sample is (12.1), or both: English unless chosen.
        $this->assertSame(['english', 'english_first', 'arabic_first'], array_keys($this->templates->languages($gulf)));
        $this->assertSame('english', $this->templates->for($gulf)->language);
    }

    public function test_the_printing_order_is_the_fallback(): void
    {
        app(PrintPreferences::class)->setLanguageOrder($this->organization, PrintPreferences::ENGLISH_FIRST);

        $this->assertFalse($this->templates->for($this->organization->fresh())->arabicFirst);
    }

    public function test_every_change_is_audited(): void
    {
        $scope = Scope::organization((string) $this->organization->id);
        $this->templates->save(Level::Organization, $scope, ['terms_en' => 'Payment within 30 days'], $this->organization, $this->admin->id);
        $this->templates->reset(Level::Organization, $scope, $this->admin->id);

        $rows = DB::table('audit_logs')->where('action', 'customization.changed')->where('user_id', $this->admin->id)->orderBy('created_at')->get();

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('invoices.template', (string) $rows[0]->metadata);
        $this->assertStringContainsString('Payment within 30 days', (string) $rows[0]->new_values);
    }

    private function branch(Organization $organization, string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id,
            'name' => $name,
            'device_serial' => 'EGS-'.str_replace(' ', '-', $name),
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]));
    }
}
