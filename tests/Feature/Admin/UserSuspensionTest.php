<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Locking an account out, and letting it back in.
 *
 * The reason is required and kept in the audit trail, through the input
 * AuditAdminAction records. Someone reactivating an account later needs to
 * know why it was locked, and the users table has nowhere to say.
 */
class UserSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'suspension-token';

    private User $admin;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_platform_admin' => true]);
        $this->clerk = User::factory()->create();
    }

    public function test_an_account_is_suspended_with_reason(): void
    {
        $this->act('suspend', $this->clerk, ['reason' => 'Left the company'])
            ->assertRedirect(route('admin.users.show', $this->clerk->id))
            ->assertSessionHas('success');

        $this->assertSame('suspended', $this->clerk->fresh()->status);

        $entry = AuditLog::where('action', 'admin.users.suspend')->first();

        $this->assertSame((string) $this->clerk->id, $entry?->entity_id);
        $this->assertSame((string) $this->admin->id, $entry->user_id);
        $this->assertSame('succeeded', $entry->metadata['outcome'] ?? null);
        $this->assertSame('Left the company', $entry->metadata['input']['reason'] ?? null);
    }

    public function test_a_suspension_needs_a_reason(): void
    {
        $this->act('suspend', $this->clerk, ['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame('active', $this->clerk->fresh()->status);
    }

    /**
     * An admin who locks themselves out has no console left to undo it from.
     */
    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->act('suspend', $this->admin, ['reason' => 'Testing'])->assertSessionHas('error');

        $this->assertSame('active', $this->admin->fresh()->status);

        // Refused, and still on the record: who tried matters as much as who
        // succeeded.
        $this->assertSame('refused', AuditLog::where('action', 'admin.users.suspend')->first()?->metadata['outcome']);
    }

    public function test_a_suspended_account_is_reactivated(): void
    {
        $this->clerk->update(['status' => 'suspended']);

        $this->act('reactivate', $this->clerk)->assertSessionHas('success');

        $this->assertSame('active', $this->clerk->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'admin.users.reactivate')->count());
    }

    /**
     * @param  array<string, string>  $data
     */
    private function act(string $action, User $target, array $data = []): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['_token' => self::TOKEN])
            ->post('/admin/users/'.$target->id.'/'.$action, ['_token' => self::TOKEN, ...$data]));
    }
}
