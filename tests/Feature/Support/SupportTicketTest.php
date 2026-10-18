<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Mail\TicketMail;
use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A customer asking for help from inside the portal, and support answering.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('support');
        config(['support.notify_address' => 'help@masaar.test']);

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->member = User::factory()->create(['email' => 'sara@acme.test']);
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $this->support->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_a_member_opens_a_ticket(): void
    {
        $this->open()->assertRedirect();

        $ticket = SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->firstOrFail());
        $this->assertSame(1, $ticket->number);
        $this->assertSame('T-000001', $ticket->reference());
        $this->assertSame(TicketStatus::Open, $ticket->status);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('help@masaar.test'));
    }

    public function test_numbers_follow_on(): void
    {
        $this->open();
        $this->open();

        $this->assertSame([1, 2], SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->orderBy('number')->pluck('number')->all()));
    }

    public function test_an_attachment_is_kept_privately(): void
    {
        $this->open(['files' => [UploadedFile::fake()->createWithContent('error.txt', 'ZATCA said no')]]);

        $ticket = $this->ticket();
        $attachment = $ticket->messages->first()->attachments->first();

        $this->assertNotNull($attachment);
        Storage::disk('support')->assertExists($attachment->path);

        $this->portal()->get(route('portal.support.attachment', [$ticket->id, $attachment->id]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('error.txt');
    }

    public function test_a_disallowed_file_is_refused(): void
    {
        $this->open(['files' => [UploadedFile::fake()->createWithContent('run.html', '<html><script>alert(1)</script></html>')]])
            ->assertSessionHas('error');

        $this->assertSame(0, SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->count()));
    }

    public function test_another_company_cannot_see_it(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($rival->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($outsider)->withSession(['portal_org_id' => $rival->id])
            ->get(route('portal.support.show', $ticket->id))
            ->assertNotFound();
    }

    /**
     * Support's reply hands the ticket to the customer and tells them.
     */
    public function test_support_replies_and_emails(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), ['body' => 'Try again now.'])
            ->assertSessionHas('success');

        $this->assertSame(TicketStatus::Waiting, $ticket->fresh()->status);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('sara@acme.test'));
    }

    /**
     * A customer who chose not to be emailed support's replies is not; the
     * reply is on the ticket all the same.
     */
    public function test_reply_emails_follow_the_preference(): void
    {
        app(Customizations::class)->set('notifications.support_replies', Level::User, Scope::member($this->acme->id, $this->member->id), false, $this->member->id);
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), ['body' => 'Try again now.'])->assertSessionHas('success');

        Mail::assertNotSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('sara@acme.test'));
        $this->assertSame(TicketStatus::Waiting, $ticket->fresh()->status);
    }

    public function test_the_customer_reply_reopens_it(): void
    {
        $this->open();
        $ticket = $this->ticket();
        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), ['body' => 'Fixed?']);

        $this->portal()->post(route('portal.support.reply', $ticket->id), ['body' => 'Still broken.']);

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    /**
     * A note between admins never reaches the customer: not on the page, not
     * by email.
     */
    public function test_an_internal_note_stays_internal(): void
    {
        $this->open();
        $ticket = $this->ticket();
        Mail::fake();

        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), [
            'body' => 'Their certificate expired last week.',
            'internal' => '1',
        ]);

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        Mail::assertNothingSent();
        $this->portal()->get(route('portal.support.show', $ticket->id))
            ->assertOk()
            ->assertDontSee('Their certificate expired last week.');
    }

    public function test_a_note_attachment_is_hidden(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), [
            'body' => 'Log',
            'internal' => '1',
            'files' => [UploadedFile::fake()->createWithContent('log.txt', 'secret')],
        ]);

        $attachment = SupportMessage::query()->where('internal', true)->firstOrFail()->attachments->first();

        $this->portal()->get(route('portal.support.attachment', [$ticket->id, $attachment->id]))->assertNotFound();
    }

    public function test_an_auditor_cannot_reply(): void
    {
        $this->open();
        $ticket = $this->ticket();
        $this->support->forceFill(['platform_role' => 'auditor'])->save();

        $this->actingAs($this->support)->get(route('admin.support.show', $ticket->id))->assertOk();
        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), ['body' => 'Hi'])->assertForbidden();
    }

    public function test_support_assigns_and_resolves(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.assign', $ticket->id), ['assigned_to' => $this->support->id]);
        $this->actingAs($this->support)->post(route('admin.support.status', $ticket->id), ['status' => 'resolved']);

        $fresh = $ticket->fresh();
        $this->assertSame($this->support->id, $fresh->assigned_to);
        $this->assertSame(TicketStatus::Resolved, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_only_support_staff_are_assignable(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.assign', $ticket->id), ['assigned_to' => $this->member->id])
            ->assertSessionHas('error');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_the_queue_lists_open_tickets(): void
    {
        $this->open(['subject' => 'Invoice rejected']);

        $this->actingAs($this->support)->get(route('admin.support.index'))
            ->assertOk()
            ->assertSee('Invoice rejected')
            ->assertSee('Acme Trading');
    }

    public function test_the_customer_marks_it_solved(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->portal()->post(route('portal.support.resolve', $ticket->id))->assertRedirect();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    /**
     * A day's attachments per company are capped, so one sign-up cannot
     * fill the disk.
     */
    public function test_a_days_uploads_are_capped(): void
    {
        config(['support.daily_upload_mb' => 0]);

        $this->open(['files' => [UploadedFile::fake()->createWithContent('error.txt', 'ZATCA said no')]])
            ->assertSessionHas('error');

        $this->assertSame(0, SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->count()));
    }

    /**
     * The audit records that a note was written, not what it said: auditors
     * read the audit, and the note is the customer's matter.
     */
    public function test_the_audit_keeps_no_note_text(): void
    {
        $this->open();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.reply', $ticket->id), [
            'body' => 'Their certificate expired last week.',
            'internal' => '1',
        ]);

        $entry = AuditLog::query()->where('action', 'admin.support.reply')->latest()->firstOrFail();
        $this->assertStringNotContainsString('certificate expired', json_encode($entry->metadata));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function open(array $overrides = []): TestResponse
    {
        return $this->portal()->post(route('portal.support.store'), array_merge([
            'subject' => 'Clearance keeps failing',
            'category' => 'zatca',
            'priority' => 'normal',
            'body' => 'Every standard invoice comes back with an error.',
        ], $overrides));
    }

    private function portal(): static
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::withoutTenantScope(fn () => SupportTicket::query()->with('messages.attachments')->firstOrFail());
    }
}
