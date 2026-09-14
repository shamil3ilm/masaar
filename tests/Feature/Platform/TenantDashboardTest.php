<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\ChainState;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The tenant dashboard's figures, and that they are the caller's alone.
 *
 * The dashboard queries through the models and relies on BelongsToTenant to
 * confine them, so every case seeds a rival organization whose rows must not
 * appear in any count.
 */
class TenantDashboardTest extends TestCase
{
    use PlatformRows;
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-15 12:00:00');

        $this->acme = $this->organization('Acme');
        $this->rival = $this->organization('Rival');
    }

    public function test_invoice_figures_cover_own_tenant(): void
    {
        $this->seedActivity();

        $expected = [
            'total' => 3,
            'today' => 1,
            'this_month' => 2,
            'last_month' => 1,
            'by_type' => ['standard' => 3],
            'total_amount' => 430,
        ];

        $this->tenant()->getJson('/api/dashboard/invoices')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => $expected]);

        $this->tenant()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.invoices', $expected);
    }

    public function test_submission_figures_and_success_rate(): void
    {
        $this->seedActivity();

        $expected = [
            'total' => 5,
            'cleared' => 2,
            'reported' => 1,
            'rejected' => 1,
            'pending' => 1,
            'success_rate' => 75,
        ];

        $this->tenant()->getJson('/api/dashboard/submissions')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => $expected]);

        $this->tenant()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.submissions', $expected)
            ->assertJsonPath('data.certificates', ['active' => false, 'days_until_expiry' => null])
            ->assertJsonStructure(['data' => ['invoices', 'submissions', 'compliance', 'certificates', 'generated_at']]);
    }

    public function test_success_rate_without_verdicts(): void
    {
        $this->tenant()->getJson('/api/dashboard/submissions')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.success_rate', 100);
    }

    public function test_chain_intact_when_head_matches(): void
    {
        $this->chainEntry($this->acme, 1);
        $last = $this->chainEntry($this->acme, 2);
        $this->chainState($this->acme, 2, $last->invoice_id);
        $this->chainState($this->rival, 9, $this->invoice($this->rival)->id);

        $this->tenant()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.compliance', ['hash_chain_intact' => true, 'latest_icv' => 2]);
    }

    public function test_chain_broken_when_head_ahead(): void
    {
        $last = $this->chainEntry($this->acme, 1);
        $this->chainState($this->acme, 3, $last->invoice_id);

        $this->tenant()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.compliance', ['hash_chain_intact' => false, 'latest_icv' => 3]);
    }

    public function test_chain_empty_is_intact(): void
    {
        $this->tenant()->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.compliance', ['hash_chain_intact' => true, 'latest_icv' => 0]);
    }

    public function test_health_reports_queue_and_certificate(): void
    {
        $this->queueItem($this->acme, 'pending', ['queued_at' => now()->subHour()]);
        $this->queueItem($this->acme, 'pending', ['queued_at' => now()->subMinutes(5)]);
        $this->queueItem($this->acme, 'failed', ['queued_at' => now()->subHour()]);
        $this->queueItem($this->rival, 'pending', ['queued_at' => now()->subHour()]);

        $this->tenant()->getJson('/api/dashboard/health')
            ->assertOk()
            ->assertJsonPath('data.queue', ['pending' => 2, 'stuck' => 1, 'healthy' => false])
            ->assertJsonPath('data.certificates.status', 'missing')
            ->assertJsonPath('data.status', 'critical')
            ->assertJsonStructure(['data' => ['circuit_breaker', 'queue', 'certificates', 'checked_at', 'status']]);
    }

    public function test_usage_fills_each_day(): void
    {
        $this->seedActivity();

        $response = $this->tenant()->getJson('/api/dashboard/usage?period=7d')
            ->assertOk()
            ->assertJsonPath('data.period', '7d')
            ->assertJsonCount(7, 'data.data');

        $days = collect($response->json('data.data'))->keyBy('date');

        $this->assertSame('2026-03-09', $response->json('data.data.0.date'));
        $this->assertEquals(['date' => '2026-03-15', 'invoices' => 1, 'submissions' => 5], $days['2026-03-15']);
        $this->assertEquals(['date' => '2026-03-10', 'invoices' => 1, 'submissions' => 0], $days['2026-03-10']);
        $this->assertEquals(['date' => '2026-03-11', 'invoices' => 0, 'submissions' => 0], $days['2026-03-11']);

        $this->tenant()->getJson('/api/dashboard/usage?period=unknown')
            ->assertOk()
            ->assertJsonPath('data.period', '30d')
            ->assertJsonCount(30, 'data.data');
    }

    public function test_activity_lists_latest_own(): void
    {
        $this->seedActivity();
        $latest = $this->submission($this->acme, 'queued', ['created_at' => now()->addMinute()]);
        $this->submission($this->rival, 'queued', ['created_at' => now()->addMinutes(2)]);

        $response = $this->tenant()->getJson('/api/dashboard/activity?limit=2')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.activities.0.id', $latest->id)
            ->assertJsonPath('data.activities.0.status', 'queued');

        $this->assertSame(['id', 'invoice_id', 'status', 'timestamp'], array_keys($response->json('data.activities.0')));
    }

    /**
     * A licence carries its organization; the partner dashboard counts that
     * organization's rows exactly as the JWT dashboard does.
     */
    public function test_partner_dashboard_scoped_to_licence(): void
    {
        $this->seedActivity();

        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme',
            'contact_email' => 'acme@masaar.test',
            'tier' => 'starter',
        ]);

        $this->getJson('/api/v1/dashboard', [
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
        ])
            ->assertOk()
            ->assertJsonPath('data.invoices.total', 3)
            ->assertJsonPath('data.submissions.total', 5);
    }

    /**
     * A person in several organizations signs in without choosing one. With
     * no tenant to count for, the dashboard says so rather than failing.
     */
    public function test_dashboard_requires_an_organization(): void
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
        $user->organizations()->attach($this->rival->id, ['role' => 'member', 'status' => 'active']);
        $token = JWTAuth::fromUser($user);

        foreach (['', '/invoices', '/submissions', '/health', '/usage'] as $path) {
            $this->withToken($token)
                ->getJson('/api/dashboard'.$path)
                ->assertStatus(401)
                ->assertJsonPath('error.message', 'Organization context is required.');
        }
    }

    /**
     * Three Acme invoices across this month and last, five submissions today,
     * and a rival invoice and submission that must not be counted.
     */
    private function seedActivity(): void
    {
        $today = $this->invoice($this->acme, ['created_at' => '2026-03-15 09:00:00']);
        $this->invoice($this->acme, ['created_at' => '2026-03-10 09:00:00']);
        $this->invoice($this->acme, ['created_at' => '2026-02-20 09:00:00', 'total' => '200.00']);

        foreach (['cleared', 'cleared', 'reported', 'rejected', 'queued'] as $state) {
            $this->submission($this->acme, $state, ['invoice_id' => $today->id, 'created_at' => '2026-03-15 10:00:00']);
        }

        $rivalInvoice = $this->invoice($this->rival, ['created_at' => '2026-03-15 09:00:00']);
        $this->submission($this->rival, 'cleared', ['invoice_id' => $rivalInvoice->id, 'created_at' => '2026-03-15 10:00:00']);
    }

    private function chainState(Organization $organization, int $icv, string $invoiceId): void
    {
        (new ChainState)->forceFill([
            'org_id' => $organization->id,
            'last_hash' => str_repeat('d', 64),
            'last_icv' => $icv,
            'last_invoice_id' => $invoiceId,
            'certificate_id' => str_repeat('0', 64),
        ])->save();
    }

    private function tenant(): self
    {
        $user = User::query()->where('email', 'acme-admin@masaar.test')->first()
            ?? User::factory()->create(['email' => 'acme-admin@masaar.test']);

        if (! $user->belongsToOrganization($this->acme->id)) {
            $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        }

        return $this->withToken(JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user));
    }
}
