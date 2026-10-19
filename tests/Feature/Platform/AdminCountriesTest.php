<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The console's Countries page: every country with the same switch, what
 * switching it off would stop, and a record of who switched it.
 */
class AdminCountriesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'countries-token';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatoora.enabled' => true, 'peppol.countries.AE.enabled' => false]);
        $this->owner = $this->admin('owner');
    }

    /**
     * One row per registered country, with its system, organizations,
     * readiness and where its state comes from; switching Saudi Arabia off
     * warns how many stop issuing and that platform billing stops too.
     */
    public function test_page_lists_every_country(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        Organization::create(['name' => 'Legacy']);
        Organization::create(['name' => 'Gulf', 'country' => 'AE']);
        config(['billing.organization_id' => $acme->id, 'fatoora.environment' => 'simulation']);

        $this->open($this->owner)
            ->assertOk()
            ->assertSee('Saudi Arabia')
            ->assertSee('United Arab Emirates')
            ->assertSee('ZATCA (Fatoora)')
            ->assertSee('Peppol PINT AE')
            ->assertSee('simulation')
            ->assertSee('Default (deployment configuration)')
            ->assertSee('2 organizations registered there will stop issuing invoices.', false)
            ->assertSee('platform billing invoices stop too', false)
            ->assertSee('data-confirm=', false);
    }

    /** Viewing and switching both take platform.configure. */
    /**
     * As everywhere in the console: every role may look, only a role that
     * configures the platform may switch.
     */
    public function test_switching_needs_platform_configure(): void
    {
        $operations = $this->admin('operations');

        $this->open($operations)->assertOk()->assertSee('Saudi Arabia')->assertDontSee('Switch off');
        $this->switch($operations, 'SA', false)->assertForbidden();

        $this->assertTrue(app(JurisdictionRegistry::class)->get('SA')->enabled());
    }

    /**
     * Switching is stored with who did it, takes effect at once, shows as
     * an admin switch, and is on the audit record with before and after.
     */
    public function test_switching_is_stored_and_audited(): void
    {
        $this->switch($this->owner, 'SA', false)->assertSessionHas('success');

        $this->assertFalse(app(JurisdictionRegistry::class)->get('SA')->enabled());
        $this->assertSame('ops@example.test', app(JurisdictionAvailability::class)->switched('SA')['by']);
        $this->open($this->owner)->assertSee('Admin switch, ops@example.test', false);

        $entry = AuditLog::where('action', 'admin.countries.update')->sole();
        $this->assertSame((string) $this->owner->id, $entry->user_id);
        $this->assertSame('Jurisdiction', $entry->entity_type);
        $this->assertSame('SA', $entry->entity_id);
        $this->assertSame(['enabled' => true], $entry->old_values);
        $this->assertSame(['enabled' => false], $entry->new_values);

        $this->switch($this->owner, 'AE', true)->assertSessionHas('success');
        $this->assertSame(['AE'], app(JurisdictionRegistry::class)->enabled());
    }

    public function test_unknown_country_is_refused(): void
    {
        $this->switch($this->owner, 'EG', false)->assertSessionHasErrors('country');

        $this->assertNull(app(JurisdictionAvailability::class)->switched('EG'));
    }

    private function admin(string $role): User
    {
        return User::factory()->create([
            'is_platform_admin' => true,
            'platform_role' => $role,
            'email' => $role === 'owner' ? 'ops@example.test' : $role.'@example.test',
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function open(User $as): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($as)->get('/admin/countries'));
    }

    private function switch(User $as, string $country, bool $enabled): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($as)
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/countries', ['_token' => self::TOKEN, 'country' => $country, 'enabled' => $enabled ? '1' : '0']));
    }
}
