<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Mail\InvitationMail;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Inviting people to an organization, and their joining it.
 *
 * Membership was created in one place - the founder, at registration - so a
 * company had one user for good.
 */
class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    private Organization $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(['*' => Http::response('')]);

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->owner = $this->member('owner@acme.sa', 'admin');
    }

    public function test_a_newcomer_joins_by_link(): void
    {
        $link = $this->invite('accountant@acme.sa', 'member');

        $this->post('/logout');
        $this->get($link)->assertOk()->assertSee('Create account and join');

        $this->post($link.'/join', [
            'name' => 'Omar',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ])->assertRedirect(route('portal.dashboard'));

        $joined = User::where('email', 'accountant@acme.sa')->sole();

        $this->assertAuthenticatedAs($joined);
        $this->assertTrue($joined->hasVerifiedEmail(), 'the link reached the address, which proves it');
        $this->assertSame('member', $joined->roleIn($this->acme->id));
    }

    public function test_the_page_names_the_role(): void
    {
        $link = $this->invite('books@acme.sa', 'accountant');

        $this->post('/logout');
        $this->get($link)->assertOk()->assertSee('You are invited as Accountant, at')->assertDontSee('as a member');
    }

    public function test_an_existing_account_accepts_signed_in(): void
    {
        $existing = User::factory()->create(['email' => 'cfo@acme.sa']);
        $link = $this->invite('cfo@acme.sa', 'admin');

        $this->actingAs($existing)->post($link.'/accept')->assertRedirect(route('portal.dashboard'));

        $this->assertSame('admin', $existing->fresh()->roleIn($this->acme->id));
    }

    /**
     * The link is for one address, and works once.
     */
    public function test_the_link_is_single_use(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@else.sa']);
        $invited = User::factory()->create(['email' => 'cfo@acme.sa']);
        $link = $this->invite('cfo@acme.sa', 'admin');

        $this->actingAs($stranger)->post($link.'/accept')->assertSessionHas('error');
        $this->assertNull($stranger->fresh()->roleIn($this->acme->id));

        $this->actingAs($invited)->post($link.'/accept');
        $this->actingAs($invited)->post($link.'/accept')->assertNotFound();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->invite('late@acme.sa', 'member');

        $this->travel(OrganizationInvitation::VALID_DAYS + 1)->days();
        $this->post('/logout');

        $this->get($link)->assertRedirect(route('login'));
    }

    public function test_only_an_admin_invites(): void
    {
        $member = $this->member('staff@acme.sa', 'member');

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->post('/portal/team/invitations', ['email' => 'x@acme.sa', 'role' => 'admin'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    /**
     * An organization keeps at least one admin, and nobody removes themselves.
     */
    public function test_the_last_admin_stays(): void
    {
        $staff = $this->member('staff@acme.sa', 'member');

        $this->actingAs($this->owner)->withSession(['portal_org_id' => $this->acme->id])
            ->post("/portal/team/members/{$this->owner->id}/remove")
            ->assertSessionHas('error');

        $this->actingAs($this->owner)->withSession(['portal_org_id' => $this->acme->id])
            ->post("/portal/team/members/{$staff->id}/remove")
            ->assertSessionHas('success');

        $this->assertNull($staff->fresh()->roleIn($this->acme->id));
        $this->assertSame('admin', $this->owner->fresh()->roleIn($this->acme->id));
    }

    private function invite(string $email, string $role): string
    {
        $link = null;

        $this->actingAs($this->owner)->withSession(['portal_org_id' => $this->acme->id])
            ->post('/portal/team/invitations', ['email' => $email, 'role' => $role])
            ->assertSessionHas('success');

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($email, &$link): bool {
            if ($mail->hasTo($email)) {
                $link = $mail->acceptUrl;
            }

            return $mail->hasTo($email);
        });

        return (string) parse_url((string) $link, PHP_URL_PATH);
    }

    private function member(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
