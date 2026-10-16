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
 * The buttons that cannot be undone ask first, and the asking cannot vanish.
 *
 * These confirmations were `onsubmit="return confirm('...')"`, and one of
 * them had already failed in exactly the way an inline handler fails. The
 * message for the regenerate-secret button contained an apostrophe; the
 * escaping was wrong, the apostrophe closed the JavaScript string, and the
 * browser discarded the attribute. The most destructive button in the console
 * - it stops a partner's integration immediately, with no grace period -
 * submitted without asking anything, and nothing reported it. A malformed
 * handler does not stop the form. It stops the question.
 *
 * They are `data-confirm` now, read by a delegated listener in the bundle.
 * Blade escapes the attribute, so the text can no longer become code, and the
 * message can hold an apostrophe. That also lets the content policy refuse
 * inline script without removing four confirmations on the way.
 *
 * Asserted on the rendered page rather than on the view source: an attribute
 * inside an @if nobody satisfies is not on the page, and the page is what a
 * person clicks.
 */
class DestructiveConfirmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Suspend, revoke and regenerate-secret all sit on the licence page.
     */
    public function test_the_licence_actions_ask_first(): void
    {
        $licence = $this->licence('Northwind');

        $page = $this->actingAs($this->admin())
            ->get(route('admin.licences.show', $licence->id));

        $page->assertOk();

        foreach (['Suspend this licence?', 'Revoke this licence?', 'Regenerate this secret?'] as $question) {
            $page->assertSee('data-confirm="'.$question, false);
        }
    }

    /**
     * And no form on that page carries an inline handler instead.
     *
     * The failure this guards against is a reasonable-looking edit: someone
     * adds a fourth destructive button and writes the confirmation the way
     * the other three used to be written. Under the content policy that
     * handler never runs, and the button is then the only one that does not
     * ask.
     */
    public function test_no_inline_handler_is_used(): void
    {
        $licence = $this->licence('Rawafid');

        $body = (string) $this->actingAs($this->admin())
            ->get(route('admin.licences.show', $licence->id))
            ->getContent();

        foreach (['onsubmit=', 'onclick=', 'onchange='] as $handler) {
            $this->assertStringNotContainsString($handler, $body,
                'An inline '.$handler.' handler is on the page. The content policy '
                .'refuses to run it, so whatever it asked is not asked.');
        }
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function licence(string $name): License
    {
        return License::createWithCredentials([
            'organization_name' => $name,
            'contact_email' => 'ops@'.strtolower($name).'.test',
            'tier' => LicenseTier::Professional,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addYear(),
        ])['license'];
    }
}
