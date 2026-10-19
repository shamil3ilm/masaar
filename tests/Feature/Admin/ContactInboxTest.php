<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Models\ContactMessage;
use App\Domains\Platform\Website\ContactStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin › Website › Messages: the contact form's inbox.
 *
 * It takes support.manage, to read as well as to act: answering people is
 * the support role's work, and a sender - often not a customer - is owed the
 * same care with their details. An auditor reads the rest of the console but
 * not this, and a customer never reaches the console at all.
 */
class ContactInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_reads_the_inbox(): void
    {
        $message = $this->message();

        $this->actingAs($this->admin('support'))
            ->get(route('admin.website.messages.index'))
            ->assertOk()
            ->assertSee('Huda Al-Harbi')
            ->assertSee('huda@example.com');

        $this->actingAs($this->admin('support'))
            ->get(route('admin.website.messages.show', $message->id))
            ->assertOk()
            ->assertSee('We would like a plan.')
            ->assertSee('mailto:huda@example.com?subject=', false);
    }

    public function test_an_auditor_cannot_open_it(): void
    {
        $message = $this->message();
        $auditor = $this->admin('auditor');

        $this->actingAs($auditor)->get(route('admin.website.messages.index'))->assertForbidden();
        $this->actingAs($auditor)->get(route('admin.website.messages.show', $message->id))->assertForbidden();
        $this->actingAs($auditor)->post(route('admin.website.messages.archive', $message->id))->assertForbidden();
        $this->actingAs($auditor)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.website.messages.index'));
    }

    public function test_a_tenant_has_no_access(): void
    {
        $message = $this->message();
        $customer = User::factory()->create();

        $this->actingAs($customer)->get(route('admin.website.messages.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.website.messages.show', $message->id))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.website.messages.replied', $message->id))->assertForbidden();

        $this->assertSame(ContactStatus::New, $message->fresh()->status);
    }

    public function test_a_guest_must_sign_in(): void
    {
        $this->get(route('admin.website.messages.index'))->assertRedirect(route('login'));
    }

    public function test_opening_it_marks_it_read(): void
    {
        $message = $this->message();
        $admin = $this->admin('support');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('data-unread-messages', false);

        $this->actingAs($admin)->get(route('admin.website.messages.show', $message->id))->assertOk();

        $this->assertNotNull($message->fresh()->read_at);
        $this->assertSame($admin->id, $message->fresh()->read_by);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertDontSee('data-unread-messages', false);
    }

    public function test_replied_archived_and_restored(): void
    {
        $message = $this->message();
        $admin = $this->admin('support');

        $this->actingAs($admin)->post(route('admin.website.messages.replied', $message->id))
            ->assertRedirect(route('admin.website.messages.show', $message->id));
        $message->refresh();
        $this->assertSame(ContactStatus::Replied, $message->status);
        $this->assertSame($admin->id, $message->replied_by);
        $this->assertNotNull($message->replied_at);

        $this->actingAs($admin)->post(route('admin.website.messages.archive', $message->id))
            ->assertRedirect(route('admin.website.messages.index'));
        $this->assertSame(ContactStatus::Archived, $message->fresh()->status);
        $this->assertSame($admin->id, $message->fresh()->updated_by);

        $this->actingAs($admin)->post(route('admin.website.messages.restore', $message->id));
        $this->assertSame(ContactStatus::Replied, $message->fresh()->status);
    }

    public function test_the_list_filters_by_topic(): void
    {
        $this->message(['name' => 'Pricing Person', 'topic' => 'pricing']);
        $archived = $this->message(['name' => 'Country Person', 'topic' => 'country']);
        $archived->forceFill(['status' => ContactStatus::Archived])->save();
        $admin = $this->admin('support');

        $this->actingAs($admin)->get(route('admin.website.messages.index', ['topic' => 'pricing']))
            ->assertSee('Pricing Person')
            ->assertDontSee('Country Person');

        $this->actingAs($admin)->get(route('admin.website.messages.index', ['status' => 'archived']))
            ->assertSee('Country Person')
            ->assertDontSee('Pricing Person');

        $this->actingAs($admin)->get(route('admin.website.messages.index', ['period' => 'today']))
            ->assertSee('Country Person')
            ->assertSee('Pricing Person');
    }

    public function test_an_unknown_message_is_not_found(): void
    {
        $this->actingAs($this->admin('support'))
            ->get(route('admin.website.messages.show', '00000000-0000-4000-8000-000000000000'))
            ->assertNotFound();
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function message(array $overrides = []): ContactMessage
    {
        return ContactMessage::query()->create([
            'name' => 'Huda Al-Harbi',
            'email' => 'huda@example.com',
            'company' => 'Harbi Trading',
            'topic' => 'general',
            'message' => 'We would like a plan.',
            'locale' => 'en',
            'ip_address' => '127.0.0.1',
            ...$overrides,
        ]);
    }
}
