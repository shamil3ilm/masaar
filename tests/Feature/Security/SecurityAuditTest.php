<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A breach has to be reconstructable from the trail.
 *
 * ZATCA and PDPL both expect that for tax-document operations, and entity
 * auditing alone does not provide it: it records what changed, not who signed
 * in, who failed to, or who issued and revoked a credential.
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', ['email' => 'operator@masaar.test', 'password' => 'password']);

        $entry = AuditLog::where('action', 'security.login.succeeded')->first();

        $this->assertNotNull($entry, 'successful login was not audited');
        $this->assertSame((string) $user->id, $entry->entity_id);
    }

    /**
     * The one that matters most: a trail showing only successes cannot tell a
     * break-in attempt from ordinary use.
     */
    public function test_failed_login_is_recorded(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', ['email' => 'operator@masaar.test', 'password' => 'wrong']);

        $entry = AuditLog::where('action', 'security.login.failed')->first();

        $this->assertNotNull($entry, 'failed login was not audited');
        $this->assertSame('operator@masaar.test', $entry->metadata['email'] ?? null);
    }

    public function test_unknown_account_recorded(): void
    {
        $this->post('/login', ['email' => 'nobody@masaar.test', 'password' => 'guess']);

        $this->assertSame(1, AuditLog::where('action', 'security.login.failed')->count());
    }

    public function test_logout_is_recorded(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout');

        $this->assertSame(1, AuditLog::where('action', 'security.logout')->count());
    }

    /**
     * The audit records that a credential was issued, never the credential.
     */
    public function test_audit_entries_never_carry_the_secret(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', ['email' => 'operator@masaar.test', 'password' => 'sup3rs3cret']);

        $serialised = AuditLog::all()->toJson();

        $this->assertStringNotContainsString('sup3rs3cret', $serialised);
    }

    public function test_client_address_is_captured(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', ['email' => 'operator@masaar.test', 'password' => 'password']);

        $entry = AuditLog::where('action', 'security.login.succeeded')->first();

        $this->assertNotNull($entry->ip_address, 'no source address recorded');
    }
}
