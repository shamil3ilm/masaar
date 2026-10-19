<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Mail\ContactAutoReplyMail;
use App\Domains\Platform\Mail\ContactReceivedMail;
use App\Domains\Platform\Models\ContactMessage;
use App\Domains\Platform\Services\Settings;
use App\Domains\Platform\Website\ContactGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The home page's contact form sends: a message is kept for the console's
 * inbox and emailed on, and the sender is thanked on the page.
 *
 * It used to post nowhere - there was no contact.store - so the form gave
 * way to a mailto link. Each spam guard is exercised here on its own, with a
 * message that passes every other one.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['company.email' => 'shamil3ilm@gmail.com']);
        Mail::fake();
    }

    public function test_a_message_is_kept_and_thanked(): void
    {
        $this->send()->assertRedirect(url('/').'#contact')->assertSessionHas('contact_sent');

        $message = ContactMessage::query()->sole();
        $this->assertSame('Huda Al-Harbi', $message->name);
        $this->assertSame('huda@example.com', $message->email);
        $this->assertSame('Harbi Trading', $message->company);
        $this->assertSame('Saudi Arabia', $message->country);
        $this->assertSame('pricing', $message->topic->value);
        $this->assertSame('en', $message->locale);
        $this->assertSame('127.0.0.1', $message->ip_address);
        $this->assertSame('new', $message->status->value);
        $this->assertNull($message->created_by);

        $this->get('/')
            ->assertSee('data-contact-sent', false)
            ->assertSee('Thank you. Your message has arrived.')
            ->assertSee('شكراً لك. وصلتنا رسالتك.');
    }

    public function test_the_operator_is_emailed(): void
    {
        $this->send();

        Mail::assertSent(ContactReceivedMail::class, fn (ContactReceivedMail $mail): bool => $mail->hasTo('shamil3ilm@gmail.com')
            && $mail->hasReplyTo('huda@example.com', 'Huda Al-Harbi'));
    }

    public function test_the_notify_setting_names_the_inbox(): void
    {
        app(Settings::class)->put(['site.contact_notify' => 'sales@example.com']);

        $this->send();

        Mail::assertSent(ContactReceivedMail::class, fn (ContactReceivedMail $mail): bool => $mail->hasTo('sales@example.com'));
        Mail::assertNotSent(ContactReceivedMail::class, fn (ContactReceivedMail $mail): bool => $mail->hasTo('shamil3ilm@gmail.com'));
    }

    public function test_no_auto_reply_by_default(): void
    {
        $this->send();

        Mail::assertNotSent(ContactAutoReplyMail::class);
    }

    /** Both languages, and nothing the sender typed. */
    public function test_the_auto_reply_is_bilingual(): void
    {
        app(Settings::class)->put([
            'site.contact_auto_reply' => '1',
            'site.contact_auto_reply_en' => 'Thanks, we read every message.',
            'site.contact_auto_reply_ar' => 'شكراً، نقرأ كل رسالة.',
        ]);

        $this->send();

        Mail::assertSent(ContactAutoReplyMail::class, function (ContactAutoReplyMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('huda@example.com')
                && str_contains($html, 'Thanks, we read every message.')
                && str_contains($html, 'شكراً، نقرأ كل رسالة.')
                && ! str_contains($html, 'Huda')
                && ! str_contains($html, 'We would like a plan');
        });
    }

    public function test_a_filled_honeypot_is_dropped_quietly(): void
    {
        $this->send([ContactGuard::HONEYPOT => 'https://spam.example'])
            ->assertRedirect(url('/').'#contact')
            ->assertSessionHas('contact_sent');

        $this->assertSame(0, ContactMessage::query()->count());
        Mail::assertNothingSent();
    }

    public function test_a_hurried_form_is_refused(): void
    {
        $this->send([], wait: 1)
            ->assertRedirect(url('/').'#contact')
            ->assertSessionHasErrors(ContactGuard::ERROR_KEY)
            ->assertSessionHasInput('name', 'Huda Al-Harbi');

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_a_tokenless_form_is_refused(): void
    {
        $this->send([ContactGuard::STARTED => 'not-a-token'])->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_an_expired_form_is_refused(): void
    {
        $this->send([], wait: 25 * 3600)->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_too_many_links_are_refused(): void
    {
        $this->send(['message' => 'See https://a.example and https://b.example and www.c.example today'])
            ->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_spam_words_are_refused(): void
    {
        $this->send(['message' => 'We offer cheap SEO services and backlinks for your site.'])
            ->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_one_address_is_rate_limited(): void
    {
        $limit = (int) config('website.contact.per_ip_per_hour');

        for ($i = 0; $i < $limit; $i++) {
            $this->send(['email' => "sender{$i}@example.com"])->assertSessionHasNoErrors();
        }

        $this->send(['email' => 'one-more@example.com'])->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame($limit, ContactMessage::query()->count());
    }

    public function test_one_email_is_rate_limited(): void
    {
        $limit = (int) config('website.contact.per_email_per_hour');

        for ($i = 0; $i < $limit; $i++) {
            $this->send([], ip: "10.0.0.{$i}")->assertSessionHasNoErrors();
        }

        $this->send([], ip: '10.0.1.1')->assertSessionHasErrors(ContactGuard::ERROR_KEY);

        $this->assertSame($limit, ContactMessage::query()->count());
    }

    public function test_validation_keeps_what_was_typed(): void
    {
        $this->send(['email' => 'not an address', 'topic' => 'nonsense'])
            ->assertRedirect(url('/').'#contact')
            ->assertSessionHasErrors(['email', 'topic'])
            ->assertSessionHasInput('company', 'Harbi Trading');

        $this->assertSame(0, ContactMessage::query()->count());
    }

    /** CSRF: the route is a web route, and the form carries the token. */
    public function test_the_form_is_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('contact.store');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertSame(['POST'], $route->methods());

        $this->get('/')
            ->assertSee('action="'.route('contact.store').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="'.ContactGuard::HONEYPOT.'"', false)
            ->assertSee('name="'.ContactGuard::STARTED.'"', false);
    }

    public function test_the_contact_url_replaces_the_form(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->post('/admin/settings', ['site.contact_url' => 'https://example.com/contact'])->assertRedirect();
        auth()->logout();

        $this->get('/')
            ->assertDontSee('action="'.route('contact.store').'"', false)
            ->assertSee('href="https://example.com/contact"', false)
            ->assertDontSee('?topic=country#contact', false);
    }

    public function test_the_shown_address_comes_from_settings(): void
    {
        $this->get('/')->assertSee('mailto:shamil3ilm@gmail.com', false);

        app(Settings::class)->put(['site.contact_email' => 'hello@example.com']);

        $this->get('/')
            ->assertSee('mailto:hello@example.com', false)
            ->assertDontSee('mailto:shamil3ilm@gmail.com', false);
    }

    public function test_old_network_details_are_cleared(): void
    {
        $this->send();
        $this->send(['email' => 'second@example.com']);
        [$old, $recent] = ContactMessage::query()->orderBy('email', 'desc')->get()->all();
        $old->forceFill(['created_at' => now()->subDays((int) config('website.contact.keep_details_days') + 1)])->saveQuietly();

        $this->artisan('website:prune-contact-details')->assertSuccessful();

        $this->assertNull($old->fresh()->ip_address);
        $this->assertNull($old->fresh()->user_agent);
        $this->assertSame('We would like a plan for three branches and the API.', $old->fresh()->message);
        $this->assertSame('127.0.0.1', $recent->fresh()->ip_address);
    }

    /**
     * A message, as the form posts it, opened $wait seconds before.
     *
     * @param  array<string, string>  $overrides
     */
    private function send(array $overrides = [], int $wait = 10, string $ip = '127.0.0.1'): TestResponse
    {
        $started = app(ContactGuard::class)->startedToken();
        $this->travel($wait)->seconds();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'TestBrowser/1.0'])
            ->from(url('/'))
            ->post(route('contact.store'), [
                'name' => 'Huda Al-Harbi',
                'email' => 'huda@example.com',
                'company' => 'Harbi Trading',
                'country' => 'Saudi Arabia',
                'topic' => 'pricing',
                'message' => 'We would like a plan for three branches and the API.',
                ContactGuard::STARTED => $started,
                ContactGuard::HONEYPOT => '',
                ...$overrides,
            ]);

        $this->travelBack();

        return $response;
    }
}
