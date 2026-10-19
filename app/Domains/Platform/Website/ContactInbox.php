<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use App\Domains\Platform\Mail\ContactAutoReplyMail;
use App\Domains\Platform\Mail\ContactReceivedMail;
use App\Domains\Platform\Models\ContactMessage;
use App\Domains\Platform\Services\PublicSite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The home page's contact form, from the message arriving to the console's
 * Website inbox answering it.
 *
 * A message is kept first and announced second: a mail server that is down
 * loses no message, because the inbox has it either way.
 */
class ContactInbox
{
    /** The longest a stored browser string is; the column's width. */
    private const USER_AGENT_MAX = 255;

    public function __construct(
        private readonly ContactGuard $guard,
        private readonly PublicSite $site,
    ) {}

    /**
     * Keep a message and tell whoever reads them; null when the honeypot
     * says a script sent it and it was dropped.
     *
     * @param  array{name: string, email: string, company?: string|null, country?: string|null, topic: string, message: string}  $input
     *
     * @throws ValidationException when a guard refuses it
     */
    public function receive(array $input, ?string $honeypot, ?string $started, string $ip, ?string $userAgent): ?ContactMessage
    {
        if ($this->guard->isBot($honeypot)) {
            return null;
        }

        $this->guard->check($started, $input['email'], $input['message'], $ip);

        $message = ContactMessage::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'company' => $input['company'] ?? null,
            'country' => $input['country'] ?? null,
            'topic' => $input['topic'],
            'message' => $input['message'],
            'locale' => app()->getLocale(),
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, self::USER_AGENT_MAX, ''),
        ]);

        $this->guard->hit($input['email'], $ip);
        $this->announce($message);

        return $message;
    }

    public function find(string $id): ?ContactMessage
    {
        return ContactMessage::query()->find($id);
    }

    /** Messages nobody in the console has opened yet, archived ones aside. */
    public function unread(): int
    {
        return ContactMessage::query()
            ->whereNull('read_at')
            ->where('status', '!=', ContactStatus::Archived->value)
            ->count();
    }

    /** Opened in the console: the first reader is kept. */
    public function markRead(ContactMessage $message, string $actorId): void
    {
        if ($message->read_at !== null) {
            return;
        }

        $message->read_at = now();
        $message->read_by = $actorId;
        $message->save();
    }

    /** Someone wrote back to the sender, from their own mail. */
    public function markReplied(ContactMessage $message, string $actorId): void
    {
        $message->status = ContactStatus::Replied;
        $message->replied_at = now();
        $message->replied_by = $actorId;
        $message->save();
    }

    public function archive(ContactMessage $message): void
    {
        $message->status = ContactStatus::Archived;
        $message->save();
    }

    /** Back where it was before it was archived: replied, if it ever was. */
    public function restore(ContactMessage $message): void
    {
        $message->status = $message->replied_at === null ? ContactStatus::New : ContactStatus::Replied;
        $message->save();
    }

    /**
     * Clear the network address and browser of messages older than the
     * keeping period. The messages themselves stay.
     *
     * @return int how many were cleared
     */
    public function pruneDetails(): int
    {
        $days = (int) config('website.contact.keep_details_days');
        $cleared = 0;

        ContactMessage::query()
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($query) => $query->whereNotNull('ip_address')->orWhereNotNull('user_agent'))
            ->lazyById(200)
            ->each(function (ContactMessage $message) use (&$cleared): void {
                $message->ip_address = null;
                $message->user_agent = null;
                $message->save();
                $cleared++;
            });

        return $cleared;
    }

    /**
     * The notification, and the automatic reply when it is on. A failure is
     * logged and swallowed: the message is already kept and in the inbox.
     */
    private function announce(ContactMessage $message): void
    {
        $to = $this->site->notifyEmail();

        try {
            if ($to !== null) {
                // In the console's language, whatever the sender's page was in.
                Mail::to($to)->locale((string) config('app.locale'))->send(new ContactReceivedMail($message));
            }

            $reply = $this->site->autoReply();

            if ($reply !== null) {
                Mail::to($message->email)->locale($message->locale)->send(new ContactAutoReplyMail($reply['en'], $reply['ar'], $message->locale));
            }
        } catch (Throwable $e) {
            Log::warning('contact.mail_failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }
    }
}
