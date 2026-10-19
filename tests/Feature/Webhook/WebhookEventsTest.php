<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CsidOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OnboardingEvents;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OrganizationOnboarding;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Services\WebhookService;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Every event an endpoint may subscribe to is one the platform sends, and
 * every one it sends can be subscribed to.
 *
 * invoice.warning and invoice.failed were sent and could not be named, so
 * only a "*" endpoint heard of an invoice accepted with warnings or a
 * failed filing. invoice.created and invoice.updated could be named and
 * were never sent; they are no longer offered. The onboarding steps are now
 * announced, to the organization whose step it was and to nobody else.
 */
class WebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme', 'name_ar' => 'أكمي', 'country' => 'SA', 'vat_number' => '300000000000003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
    }

    public function test_warning_and_failure_can_be_named(): void
    {
        $this->api()->postJson('/api/webhooks', ['url' => 'https://203.0.113.20/hooks', 'events' => ['invoice.warning', 'invoice.failed']])
            ->assertCreated();

        Queue::fake();
        app(WebhookService::class)->dispatch((string) $this->acme->id, 'invoice.warning', ['invoice_id' => 'x']);
        app(WebhookService::class)->dispatch((string) $this->acme->id, 'invoice.failed', ['invoice_id' => 'x']);

        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    public function test_drafts_are_not_offered(): void
    {
        foreach (['invoice.created', 'invoice.updated'] as $event) {
            $this->api()->postJson('/api/webhooks', ['url' => 'https://203.0.113.20/hooks', 'events' => [$event]])
                ->assertStatus(422);
        }
    }

    public function test_onboarding_steps_reach_their_organization(): void
    {
        Queue::fake();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $this->webhook($this->acme);
        $this->webhook($rival);

        $this->mock(CsidOnboarding::class, function ($mock): void {
            $mock->shouldReceive('requestComplianceCsid')->once()->andReturn([
                'ccsid' => 'ccsid', 'secret' => 'secret', 'requestId' => '1', 'privateKey' => 'key',
            ]);
            $mock->shouldReceive('runComplianceChecks')->once()->andReturn(['passed' => true, 'results' => []]);
            $mock->shouldReceive('requestProductionCsid')->once()->andReturn(['pcsid' => 'pcsid', 'secret' => 'secret']);
        });

        $onboarding = app(OrganizationOnboarding::class);
        $onboarding->requestCcsid($this->acme, '123456', ['common_name' => 'EGS-1', 'serial_number' => '1-'.Brand::csrSolution().'|2-1|3-1']);
        $onboarding->runComplianceCheck($this->acme);
        $onboarding->requestPcsid($this->acme);

        $pushed = Queue::pushed(DeliverWebhook::class);
        $this->assertSame(
            [OnboardingEvents::CCSID_OBTAINED, OnboardingEvents::COMPLIANCE_PASSED, OnboardingEvents::PCSID_OBTAINED],
            $pushed->map(fn (DeliverWebhook $job): string => $job->event)->values()->all(),
        );
        $this->assertSame([(string) $this->acme->id], $pushed->map(fn (DeliverWebhook $job): string => $job->payload['org_id'])->unique()->values()->all());
        $this->assertNull($pushed->first()->payload['branch_id']);
    }

    private function webhook(Organization $organization): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $organization->id, 'url' => 'https://203.0.113.20/hooks', 'secret' => 'secret', 'events' => ['*'], 'is_active' => true,
        ]));
    }

    private function api(): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user));
    }
}
