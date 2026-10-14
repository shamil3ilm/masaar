<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;

/**
 * One record of every sign-in, from the console and from the API alike.
 *
 * The two recorded differently: the console wrote security.login.succeeded
 * and .failed, the API wrote auth.login for a success and nothing for a
 * failure. An account's history therefore showed half its API activity and
 * none of the attempts against it there.
 *
 * A failure against a real account is filed under that account, so it is on
 * the account's own history rather than findable only by searching metadata
 * for the email. Nothing is said to the caller about whether the account
 * exists; this is the trail, not the response.
 */
class SignInRecorder
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function succeeded(User $user, string $surface, array $metadata = []): void
    {
        $this->audit->logSecurity('login.succeeded', 'User', (string) $user->id, [
            'surface' => $surface,
            ...$metadata,
        ], userId: (string) $user->id);
    }

    public function failed(string $email, string $surface): void
    {
        $userId = User::query()->where('email', $email)->value('id');

        $this->audit->logSecurity('login.failed', 'User', $userId === null ? null : (string) $userId, [
            'surface' => $surface,
            'email' => $email,
        ]);
    }
}
