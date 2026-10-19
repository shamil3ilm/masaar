<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Keeps scripts out of the contact form without asking a person to solve
 * a puzzle.
 *
 * Five checks, cheapest first:
 *
 *   honeypot    a field people never see and scripts fill in. Filled, the
 *               message is dropped while the sender is thanked as usual, so
 *               a script learns nothing.
 *   time trap   the page carries when it was opened, encrypted. Sent quicker
 *               than a person could type, or long after, or without it, it is
 *               refused and the person asked to send it again.
 *   rate limit  so many an hour from one network address and from one email.
 *   links       a message carrying more links than a person writes.
 *   keywords    words that only spam uses.
 *
 * Everything but the honeypot is refused visibly, with what was typed kept,
 * so a person caught by mistake can fix it or write instead.
 */
class ContactGuard
{
    /** The field people never see. */
    public const HONEYPOT = 'website';

    /** The field carrying when the page was opened. */
    public const STARTED = 'started';

    /** Where a refusal is shown on the form. */
    public const ERROR_KEY = 'contact';

    private const DECAY_SECONDS = 3600;

    /**
     * Words that turn up in link spam and almost nowhere else. Whole words,
     * any case. A business asking about e-invoicing has no use for them.
     */
    private const SPAM_WORDS = [
        'viagra', 'cialis', 'casino', 'porn', 'escort', 'backlinks', 'seo services',
        'guest post', 'crypto giveaway', 'forex signals', 'payday loan', 'rank your website',
    ];

    /** The value the form carries, written when the page is drawn. */
    public function startedToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Whether the message is a script's that should be dropped without a
     * word: the honeypot was filled in.
     */
    public function isBot(?string $honeypot): bool
    {
        if (trim((string) $honeypot) === '') {
            return false;
        }

        Log::info('contact.dropped', ['reason' => 'honeypot']);

        return true;
    }

    /**
     * Refuse what a person should be told about.
     *
     * @throws ValidationException
     */
    public function check(?string $started, string $email, string $message, string $ip): void
    {
        $this->assertTimely($started);
        $this->assertWithinLimits($email, $ip);
        $this->assertNotSpam($message);
    }

    /** Count a message that was kept, against both limits. */
    public function hit(string $email, string $ip): void
    {
        RateLimiter::hit($this->ipKey($ip), self::DECAY_SECONDS);
        RateLimiter::hit($this->emailKey($email), self::DECAY_SECONDS);
    }

    private function assertTimely(?string $started): void
    {
        try {
            $openedAt = (int) Crypt::decryptString((string) $started);
        } catch (DecryptException) {
            $openedAt = 0;
        }

        $elapsed = now()->getTimestamp() - $openedAt;
        $minimum = (int) config('website.contact.min_seconds');
        $maximum = (int) config('website.contact.max_age_hours') * 3600;

        if ($openedAt <= 0 || $elapsed > $maximum) {
            $this->refuse('stale', __('This form has expired. Please send your message again.'));
        }

        if ($elapsed < $minimum) {
            $this->refuse('too_fast', __('That was sent too quickly. Please check your message and send it again.'));
        }
    }

    private function assertWithinLimits(string $email, string $ip): void
    {
        $perIp = (int) config('website.contact.per_ip_per_hour');
        $perEmail = (int) config('website.contact.per_email_per_hour');

        foreach ([[$this->ipKey($ip), $perIp], [$this->emailKey($email), $perEmail]] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                $this->refuse('rate_limited', __('You have sent several messages already. Please try again in :minutes minutes.', ['minutes' => $minutes]));
            }
        }
    }

    private function assertNotSpam(string $message): void
    {
        $links = preg_match_all('~(?:https?://|www\.)\S+~i', $message);

        if ($links > (int) config('website.contact.max_links')) {
            $this->refuse('links', __('Please send fewer links. You can describe what you need and we will ask for the rest.'));
        }

        $pattern = '/\b(?:'.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), self::SPAM_WORDS)).')\b/i';

        if (preg_match($pattern, $message) === 1) {
            $this->refuse('keywords', __('This message looks like spam to us. If it is not, please write to us by email.'));
        }
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $reason, string $message): never
    {
        Log::info('contact.refused', ['reason' => $reason]);

        throw ValidationException::withMessages([self::ERROR_KEY => $message]);
    }

    private function ipKey(string $ip): string
    {
        return 'contact:ip:'.hash('sha256', $ip);
    }

    private function emailKey(string $email): string
    {
        return 'contact:email:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
