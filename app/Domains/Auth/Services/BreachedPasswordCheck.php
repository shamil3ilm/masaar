<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Password::uncompromised(), with a short timeout and a stated outcome when
 * the breach range service cannot answer.
 *
 * Laravel's own verifier waits up to thirty seconds for the service, so a
 * slow service held every registration, sign-up, invitation, password change
 * and reset up that long; and it reported a failure as an exception, or not
 * at all for an error status. Here the service gets
 * security.password_breach_check.timeout_seconds (3 by default) to connect
 * and answer. When it does not, or answers with an error, the password is
 * judged on the other rules alone and a warning is logged - naming only what
 * went wrong, never the password or its hash. A password the service lists
 * more often than the threshold is still refused.
 *
 * Only the first five characters of the password's SHA-1 leave the server
 * (the range API's k-anonymity), as before.
 */
final class BreachedPasswordCheck implements UncompromisedVerifier
{
    public function __construct(private readonly Factory $http) {}

    /**
     * @param  array{value: mixed, threshold: int}  $data
     */
    public function verify($data): bool
    {
        $value = (string) $data['value'];

        if ($value === '') {
            return false;
        }

        $hash = strtoupper(sha1($value));
        $prefix = substr($hash, 0, 5);

        try {
            $response = $this->http
                ->withHeaders(['Add-Padding' => 'true'])
                ->connectTimeout($this->timeout())
                ->timeout($this->timeout())
                ->get(rtrim((string) config('security.password_breach_check.range_url'), '/').'/'.$prefix);
        } catch (Throwable $e) {
            $this->unavailable(class_basename($e));

            return true;
        }

        if (! $response->successful()) {
            $this->unavailable('status '.$response->status());

            return true;
        }

        foreach (preg_split('/\r?\n/', trim($response->body())) ?: [] as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');

            if ($prefix.$suffix === $hash && (int) $count > (int) $data['threshold']) {
                return false;
            }
        }

        return true;
    }

    /** Seconds to connect, and again to answer. */
    public function timeout(): int
    {
        return max(1, (int) config('security.password_breach_check.timeout_seconds', 3));
    }

    private function unavailable(string $reason): void
    {
        Log::warning('The breached-password check could not be made; the password was judged on the other rules', [
            'reason' => $reason,
            'timeout_seconds' => $this->timeout(),
        ]);
    }
}
