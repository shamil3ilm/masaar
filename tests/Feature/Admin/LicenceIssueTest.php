<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issuing a licence from the console, and the one moment its secret exists.
 *
 * createLicense() has always returned the plaintext secret and nothing has
 * ever shown it: issuing a partner a licence meant the CLI, where the operator
 * read it off stdout. The row keeps only api_secret_hash, so the array the
 * create call returns is the only place the secret is ever readable. If the
 * screen does not print it there it is gone, and the only remedy is
 * regenerating it, which breaks whatever the partner already configured.
 *
 * So the subject here is not only that a licence appears in the table. It is
 * that the secret is shown exactly once and never again.
 */
class LicenceIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_creates_a_licence(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.licences.create'))
            ->post('/admin/licences', $this->form());

        $this->assertDatabaseHas('licenses', ['organization_name' => 'Northwind']);

        $licence = License::query()->where('organization_name', 'Northwind')->firstOrFail();

        $response->assertRedirect(route('admin.licences.show', $licence->id));
        $response->assertSessionHas('success', 'Licence issued.');

        $this->assertSame(LicenseTier::Professional, $licence->tier);
        $this->assertSame('ops@northwind.test', $licence->contact_email);
        $this->assertNotSame('', (string) $licence->api_key);
    }

    /**
     * Once, on the page the issue redirects to, and never afterwards.
     *
     * Flashed rather than stored, so the second look at the same page has
     * nothing to show - which is the honest behaviour, because the database
     * holds only the hash.
     */
    public function test_the_secret_is_shown_once(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.licences.create'))
            ->post('/admin/licences', $this->form());

        $response->assertSessionHas('api_secret');

        $secret = (string) session('api_secret');
        $destination = (string) $response->headers->get('Location');

        $this->assertNotSame('', $secret);

        $this->actingAs($admin)
            ->get($destination)
            ->assertSee('Northwind')
            ->assertSee($secret)
            ->assertSee('Copy this secret now')
            ->assertSee('it cannot be', false);

        $this->actingAs($admin)
            ->get($destination)
            ->assertSee('Northwind')
            ->assertDontSee($secret);

        // Never persisted: what the row keeps is the HMAC, which is not the
        // secret and does not contain it.
        $licence = License::query()->where('organization_name', 'Northwind')->firstOrFail();

        $this->assertNotSame($secret, $licence->api_secret_hash);
        $this->assertStringNotContainsString($secret, $licence->api_secret_hash);
        $this->assertTrue($licence->verifySecret($secret));
    }

    /**
     * And the rejected form comes back with what was typed into it.
     */
    public function test_the_form_validates(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->from(route('admin.licences.create'))
            ->post('/admin/licences', $this->form(['organization_name' => '']));

        $response->assertRedirect(route('admin.licences.create'));
        $response->assertSessionHasErrors('organization_name');
        $response->assertSessionMissing('api_secret');

        $this->assertDatabaseCount('licenses', 0);

        $this->actingAs($admin)
            ->get(route('admin.licences.create'))
            ->assertSee('Issue licence')
            ->assertSee('ops@northwind.test')
            ->assertSee('The organization name field is required.');
    }

    /**
     * A failure that is "no such licence" cannot be reported on that licence's
     * page.
     *
     * attempt() redirected to admin.licences.show for the id that had just
     * raised findOrFail, so the admin was sent to a 404 and the flash
     * explaining what went wrong was consumed by a page that never rendered.
     * The list is somewhere that exists.
     */
    public function test_a_missing_licence_redirects_home(): void
    {
        $admin = $this->admin();
        $missing = '0193ffff-0000-7000-8000-0000000dead1';

        $response = $this->actingAs($admin)
            ->post("/admin/licences/{$missing}/reactivate");

        $response->assertRedirect(route('admin.licences.index'));
        $response->assertSessionHas('error');

        $message = (string) session('error');

        $this->assertStringContainsString($missing, $message);

        $this->actingAs($admin)
            ->get('/admin/licences')
            ->assertSee($message);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return [
            'organization_name' => 'Northwind',
            'contact_email' => 'ops@northwind.test',
            'tier' => LicenseTier::Professional->value,
            ...$overrides,
        ];
    }
}
