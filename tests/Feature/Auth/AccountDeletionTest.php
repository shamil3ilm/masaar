<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A person deleting their own account, as the PDPL lets them.
 *
 * Their personal data goes; the row stays, anonymised, so the invoices and
 * audit entries that name it still point somewhere.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->user = User::factory()->create(['email' => 'sara@acme.test', 'name' => 'Sara']);
        $this->user->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_the_account_is_anonymised(): void
    {
        $this->actingAs($this->user)->post('/account/delete', ['password' => 'password', 'confirm' => 'DELETE'])
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $fresh = $this->user->fresh();
        $this->assertSame('Deleted user', $fresh->name);
        $this->assertStringEndsWith('@deleted.invalid', $fresh->email);
        $this->assertSame('deleted', $fresh->status);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertDatabaseHas('organization_user', ['user_id' => $this->user->id, 'status' => 'removed']);
    }

    public function test_it_cannot_sign_in_after(): void
    {
        $this->actingAs($this->user)->post('/account/delete', ['password' => 'password', 'confirm' => 'DELETE']);

        $this->post('/login', ['email' => 'sara@acme.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_password_is_required(): void
    {
        $this->actingAs($this->user)->post('/account/delete', ['password' => 'wrong', 'confirm' => 'DELETE'])
            ->assertSessionHas('error');

        $this->assertSame('Sara', $this->user->fresh()->name);
    }

    public function test_delete_must_be_typed(): void
    {
        $this->actingAs($this->user)->post('/account/delete', ['password' => 'password', 'confirm' => 'yes'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame('Sara', $this->user->fresh()->name);
    }

    /**
     * The only admin leaving would leave the others with nobody to manage
     * the organization.
     */
    public function test_the_only_admin_must_hand_over(): void
    {
        $this->acme->users()->updateExistingPivot($this->user->id, ['role' => 'admin']);
        $other = User::factory()->create();
        $other->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($this->user)->post('/account/delete', ['password' => 'password', 'confirm' => 'DELETE'])
            ->assertSessionHas('error');

        $this->assertSame('Sara', $this->user->fresh()->name);
    }

    public function test_a_platform_admin_is_refused(): void
    {
        $this->user->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($this->user)->post('/account/delete', ['password' => 'password', 'confirm' => 'DELETE'])
            ->assertSessionHas('error');

        $this->assertSame('Sara', $this->user->fresh()->name);
    }
}
