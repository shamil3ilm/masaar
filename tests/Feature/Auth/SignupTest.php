<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A business signs itself up, proves its email address, and waits for review.
 *
 * There was no way in for a business at all: no sign-up page, no email
 * verification and no way back from a forgotten password. Organizations came
 * from the API alone, active at once, with no review before they could file.
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        // The breached-password check asks a range API; nothing here is in it.
        Http::fake(['*' => Http::response('')]);
        Notification::fake();
    }

    public function test_a_business_signs_itself_up(): void
    {
        $this->post('/register', $this->form())->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'owner@acme.sa')->sole();
        $organization = $user->organizations()->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(Organization::STATUS_PENDING_REVIEW, $organization->status);
        $this->assertSame('admin', $organization->pivot->role);
        $this->assertTrue($organization->hasCompleteZatcaProfile());
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_portal_waits_for_verification(): void
    {
        $this->post('/register', $this->form());

        $this->get('/portal')->assertRedirect(route('verification.notice'));
    }

    public function test_the_emailed_link_verifies(): void
    {
        $this->post('/register', $this->form());
        $user = User::where('email', 'owner@acme.sa')->sole();

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($link)->assertRedirect(route('portal.dashboard'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * The VAT number is the taxpayer; a second organization with it is a
     * duplicate or somebody else's claim.
     */
    public function test_a_claimed_vat_is_refused(): void
    {
        Organization::create(['name' => 'Acme', 'country' => 'SA', 'compliance_profile' => ['vat_number' => '300000000000003']]);

        $this->post('/register', $this->form())->assertSessionHasErrors('vat_number');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/register', $this->form(['terms' => null]))->assertSessionHasErrors('terms');
    }

    public function test_signup_can_be_switched_off(): void
    {
        config(['signup.enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->form())->assertNotFound();
    }

    /**
     * Through the API too, or it would be the way around the review.
     */
    public function test_api_organizations_wait_for_review(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson('/api/organizations', ['name' => 'Gulf Trading'])
            ->assertCreated()
            ->assertJsonPath('data.organization.status', Organization::STATUS_PENDING_REVIEW);
    }

    public function test_a_forgotten_password_is_reset(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.sa']);
        $token = null;

        $this->post('/forgot-password', ['email' => 'owner@acme.sa'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'owner@acme.sa',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    /**
     * The same answer whether or not the address has an account.
     */
    public function test_reset_does_not_reveal_accounts(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@acme.sa'])
            ->assertSessionHas('status', 'If that address has an account, a link to reset its password is on its way.')
            ->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_filter(array_merge([
            'name' => 'Sara Al-Qahtani',
            'email' => 'owner@acme.sa',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'company_name' => 'Acme Trading',
            'company_name_ar' => 'شركة أكمي',
            'vat_number' => '300000000000003',
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'terms' => '1',
        ], $overrides), fn (?string $value): bool => $value !== null);
    }
}
