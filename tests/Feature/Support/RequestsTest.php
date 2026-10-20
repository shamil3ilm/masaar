<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Contracts\ChargeSource;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Support\Enums\RequestImportance;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Mail\TicketMail;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Services\RequestCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The portal's Requests beyond help: a customization and a feature request,
 * each a support ticket with its stages, and a customization's price
 * reaching billing through ChargeSource.
 */
class RequestsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('support');

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->member = User::factory()->create(['email' => 'sara@acme.test']);
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_a_member_asks_for_a_feature(): void
    {
        $this->openFeature(['files' => [UploadedFile::fake()->createWithContent('mockup.png', 'png')]])->assertRedirect();

        $ticket = $this->ticket();
        $this->assertSame(TicketKind::Feature, $ticket->kind);
        $this->assertSame(RequestStage::Submitted, $ticket->stage);
        $this->assertSame('integration', $ticket->featureRequest->category);
        $this->assertSame('Odoo', $ticket->featureRequest->system_name);
        $this->assertSame(RequestImportance::Important, $ticket->featureRequest->importance);
    }

    public function test_a_retired_category_is_refused(): void
    {
        app(RequestCategories::class)->retire('ocr', true, null);

        $this->openFeature(['feature_category' => 'ocr'])->assertSessionHasErrors('feature_category');

        $this->assertSame(0, SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->count()));
    }

    public function test_only_an_integration_names_a_system(): void
    {
        $this->openFeature(['feature_category' => 'reports', 'system_name' => 'Odoo']);

        $this->assertNull($this->ticket()->featureRequest->system_name);
    }

    public function test_a_member_asks_for_a_customization(): void
    {
        $this->openCustomization()->assertRedirect();

        $ticket = $this->ticket();
        $this->assertSame(TicketKind::Customization, $ticket->kind);
        $this->assertSame(RequestStage::Submitted, $ticket->stage);
        $this->assertNotNull($ticket->customization);
        $this->assertNull($ticket->customization->price);
    }

    public function test_each_kind_has_its_form(): void
    {
        foreach (['support' => 'About', 'feature' => 'How much it matters', 'customization' => 'Nothing is charged before you agree.'] as $kind => $text) {
            $this->portal()->get(route('portal.support.create', ['kind' => $kind]))->assertOk()->assertSee($text);
        }
    }

    public function test_the_list_shows_every_kind(): void
    {
        $this->openFeature(['subject' => 'Connect to Odoo']);
        $this->openCustomization(['subject' => 'Monthly branch report']);

        $this->portal()->get(route('portal.support.index'))->assertOk()
            ->assertSee('Connect to Odoo')->assertSee('Monthly branch report')
            ->assertSee('Feature request')->assertSee('Customization');
    }

    /**
     * A stage move is a message the customer reads, and they are emailed.
     */
    public function test_support_moves_a_request_on(): void
    {
        $this->openFeature();
        $ticket = $this->ticket();

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'planned', 'note' => 'In the next quarter.'])
            ->assertRedirect();

        $fresh = $this->ticket();
        $this->assertSame(RequestStage::Planned, $fresh->stage);
        $this->assertSame(RequestStage::Planned, $fresh->messages->last()->stage);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('sara@acme.test') && $mail->headline === 'Your request has an update');

        $this->portal()->get(route('portal.support.show', $ticket->id))->assertOk()->assertSee('In the next quarter.')->assertSee('Planned');
    }

    public function test_a_final_stage_resolves_it(): void
    {
        $this->openFeature();
        $ticket = $this->ticket();

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'declined', 'note' => 'Out of scope.']);

        $this->assertSame(TicketStatus::Resolved, $this->ticket()->status);
    }

    public function test_opted_out_members_get_no_email(): void
    {
        app(Customizations::class)->set('notifications.request_updates', Level::User, Scope::member((string) $this->acme->id, (string) $this->member->id), '0', (string) $this->member->id);
        $this->openFeature();
        $ticket = $this->ticket();

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'under_review']);

        Mail::assertNotSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('sara@acme.test'));
    }

    public function test_a_support_ticket_has_no_stages(): void
    {
        $this->portal()->post(route('portal.support.store'), [
            'kind' => 'support', 'subject' => 'Clearance fails', 'category' => 'zatca', 'priority' => 'normal', 'body' => 'Every invoice.',
        ]);
        $ticket = $this->ticket();

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'planned'])->assertSessionHas('error');
        $this->assertNull($this->ticket()->stage);
    }

    /**
     * The price is an admin's to enter, and billing finds it once the work
     * is released - not before, and not twice.
     */
    public function test_a_released_customization_is_owed(): void
    {
        $this->openCustomization();
        $ticket = $this->ticket();
        $charges = app(ChargeSource::class);

        $this->agree($ticket, ['price' => '1500.00', 'currency' => 'SAR'])->assertSessionHas('success');
        $this->assertSame([], $charges->unbilled((string) $this->acme->id, 'SAR'));

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'released']);

        $owed = $charges->unbilled((string) $this->acme->id, 'SAR');
        $this->assertCount(1, $owed);
        $this->assertSame('1500.00', $owed[0]->amount);
        $this->assertSame($ticket->reference(), $owed[0]->reference);
        $this->assertSame([], $charges->unbilled((string) $this->acme->id, 'AED'));

        $charges->markBilled($owed[0]->sourceId, '0199a000-0000-7000-8000-000000000001');
        $this->assertSame([], $charges->unbilled((string) $this->acme->id, 'SAR'));
    }

    public function test_a_billed_price_is_fixed(): void
    {
        $this->openCustomization();
        $ticket = $this->ticket();
        $this->agree($ticket, ['price' => '1500.00', 'currency' => 'SAR']);
        app(ChargeSource::class)->markBilled((string) $ticket->customization->id, '0199a000-0000-7000-8000-000000000001');

        $this->agree($ticket, ['price' => '900.00', 'currency' => 'SAR'])->assertSessionHas('error');

        $this->assertSame('1500.00', $this->ticket()->customization->price);
    }

    public function test_work_not_billable_is_never_owed(): void
    {
        $this->openCustomization();
        $ticket = $this->ticket();
        $this->agree($ticket, ['billable' => '0', 'price' => null, 'currency' => null])->assertSessionHas('success');
        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'released']);

        $this->assertSame([], app(ChargeSource::class)->unbilled((string) $this->acme->id, 'SAR'));
    }

    /**
     * Released, its features are switched on for the organization with the
     * customization as the reason; moved off released, they are cleared.
     */
    public function test_release_switches_its_features_on(): void
    {
        $this->openCustomization();
        $ticket = $this->ticket();
        $this->agree($ticket, ['price' => '1500.00', 'currency' => 'SAR', 'features' => ['batch_submission']]);
        $overrides = app(FeatureOverrides::class);

        $this->assertNull($overrides->state((string) $this->acme->id, Feature::BatchSubmission));

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'released']);
        $this->assertSame('on', $overrides->state((string) $this->acme->id, Feature::BatchSubmission));
        $this->assertSame('Customization '.$ticket->reference(), $overrides->for((string) $this->acme->id)['batch_submission']['reason']);

        $this->admin()->post(route('admin.support.stage', $ticket->id), ['stage' => 'in_progress']);
        $this->assertNull($overrides->state((string) $this->acme->id, Feature::BatchSubmission));
    }

    public function test_support_cannot_set_a_price(): void
    {
        $this->openCustomization();
        $ticket = $this->ticket();
        $this->operations->forceFill(['platform_role' => 'support'])->save();

        $this->agree($ticket, ['price' => '1500.00', 'currency' => 'SAR'])->assertForbidden();
    }

    public function test_the_admin_ticket_shows_the_request(): void
    {
        $this->openCustomization();
        $customization = $this->ticket();
        $this->openFeature();
        $feature = SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->where('kind', 'feature')->firstOrFail());

        $this->admin()->get(route('admin.support.show', $customization->id))->assertOk()->assertSee('Save customization');
        $this->admin()->get(route('admin.support.show', $feature->id))->assertOk()->assertSee('Integration with another system')->assertSee('Odoo');
        $this->admin()->get(route('admin.support.index', ['kind' => 'feature', 'status' => 'all']))->assertOk()->assertSee($feature->subject);
    }

    public function test_admins_edit_the_categories(): void
    {
        // platform.configure: the owner's, as business types are.
        $this->admin()->post(route('admin.request-categories.store'), ['key' => 'payments', 'label' => 'Payments', 'label_ar' => 'المدفوعات'])
            ->assertForbidden();
        $this->operations->forceFill(['platform_role' => 'owner'])->save();

        $this->admin()->post(route('admin.request-categories.store'), ['key' => 'payments', 'label' => 'Payments', 'label_ar' => 'المدفوعات', 'names_system' => '1'])
            ->assertSessionHas('success');

        $category = app(RequestCategories::class)->find('payments');
        $this->assertNotNull($category);
        $this->assertTrue($category->namesSystem);
        $this->admin()->get(route('admin.request-categories.index'))->assertOk()->assertSee('المدفوعات');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function openFeature(array $overrides = []): TestResponse
    {
        return $this->portal()->post(route('portal.support.store'), array_merge([
            'kind' => 'feature',
            'subject' => 'Connect to our ERP',
            'body' => 'We enter every invoice twice today.',
            'feature_category' => 'integration',
            'system_name' => 'Odoo',
            'importance' => 'important',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function openCustomization(array $overrides = []): TestResponse
    {
        return $this->portal()->post(route('portal.support.store'), array_merge([
            'kind' => 'customization',
            'subject' => 'A sales report per branch',
            'body' => 'Every month, by product.',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function agree(SupportTicket $ticket, array $terms): TestResponse
    {
        return $this->admin()->post(route('admin.support.customization', $ticket->id), array_merge([
            'description' => 'A monthly report per branch.',
            'billable' => '1',
        ], $terms));
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function admin(): static
    {
        return $this->actingAs($this->operations);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->with(['featureRequest', 'customization', 'messages'])->latest('number')->firstOrFail());
    }
}
