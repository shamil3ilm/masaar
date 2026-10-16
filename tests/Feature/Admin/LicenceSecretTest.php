<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rotating a secret, and the honesty around it.
 *
 * The plaintext exists for one moment - the array the service returns - and is
 * stored only as an HMAC. So the screen shows it once and says it cannot be
 * shown again, rather than implying it can be looked up.
 *
 * Rotation is more destructive than the word suggests: the partner's current
 * secret stops working the instant it returns, mid-integration, with no grace
 * period. That is why it is a confirmed POST and not a link.
 */
class LicenceSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotating_changes_the_secret(): void
    {
        $licence = $this->licence();
        $before = $licence->api_secret_hash;

        $this->actingAs($this->admin())
            ->post('/admin/licences/'.$licence->id.'/rotate')
            ->assertRedirect(route('admin.licences.show', $licence->id))
            ->assertSessionHas('api_secret');

        $this->assertNotSame($before, $licence->fresh()->api_secret_hash);
    }

    /**
     * The new secret verifies against what was stored, and the stored value
     * does not contain it - which is what "stored only as a hash" has to mean
     * for the warning on screen to be true.
     */
    public function test_the_new_secret_is_hashed(): void
    {
        $licence = $this->licence();

        $this->actingAs($this->admin())->post('/admin/licences/'.$licence->id.'/rotate');

        $secret = (string) session('api_secret');
        $stored = (string) $licence->fresh()->api_secret_hash;

        $this->assertNotSame('', $secret);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertTrue($licence->fresh()->verifySecret($secret));
    }

    /**
     * And it is gone on the next request, which is the whole claim the warning
     * makes. A flash that survived would make the warning a lie.
     */
    public function test_the_secret_is_not_shown_twice(): void
    {
        $licence = $this->licence();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/licences/'.$licence->id.'/rotate');

        $secret = (string) session('api_secret');
        $this->assertNotSame('', $secret);

        // The redirect target is the one request that may show it. Reading the
        // flash without consuming that request and then asserting it is gone
        // would assert the opposite of the intended behaviour.
        $this->followRedirects($response)->assertSee($secret);

        $this->actingAs($admin)
            ->get('/admin/licences/'.$licence->id)
            ->assertDontSee($secret);
    }

    public function test_a_missing_licence_redirects_home(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/licences/0193ffff-0000-7000-8000-0000000dead1/rotate')
            ->assertRedirect(route('admin.licences.index'))
            ->assertSessionHas('error');
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function licence(): License
    {
        return License::createWithCredentials([
            'organization_name' => 'Northwind',
            'contact_email' => 'ops@northwind.test',
            'tier' => LicenseTier::Professional,
            'status' => LicenseStatus::Active,
        ])['license'];
    }
}
