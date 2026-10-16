<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Mail\EmailChangedMail;
use App\Domains\Auth\Mail\EmailChangeMail;
use App\Domains\Auth\Models\User;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * A person changing their own account: name, email address, password.
 *
 * There was no way to: a typo in a name stayed, a new email address needed a
 * platform admin, and a password could be changed only by forgetting it.
 */
class AccountSettings
{
    public const EMAIL_LINK_HOURS = 24;

    private const NO_PENDING_EMAIL = [
        'pending_email' => null,
        'pending_email_hash' => null,
        'pending_email_expires_at' => null,
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function setLocale(User $user, string $locale): void
    {
        $user->forceFill(['locale' => $locale])->save();
    }

    public function rename(User $user, string $name): void
    {
        $user->forceFill(['name' => $name])->save();
    }

    /**
     * The new address waits beside the current one until a link sent to it is
     * opened: a mistyped address does not lock its owner out, and nobody can
     * take an address they cannot read.
     *
     * @throws DomainException
     */
    public function requestEmailChange(User $user, string $email, string $password): void
    {
        // Whoever holds a session is not necessarily its owner, and the
        // address is how an account is recovered.
        if (! Hash::check($password, (string) $user->password)) {
            throw new DomainException(__('Your current password is not right.'));
        }

        $email = Str::lower(trim($email));

        if ($email === Str::lower((string) $user->email)) {
            throw new DomainException(__('That is already your address.'));
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException(__('Another account uses that address.'));
        }

        $token = Str::random(48);

        $user->forceFill([
            'pending_email' => $email,
            'pending_email_hash' => hash('sha256', $token),
            'pending_email_expires_at' => now()->addHours(self::EMAIL_LINK_HOURS),
        ])->save();

        Mail::to($email)->send(new EmailChangeMail(
            name: (string) $user->name,
            confirmUrl: route('account.email.confirm', $token),
            validHours: self::EMAIL_LINK_HOURS,
        ));
    }

    /**
     * @throws DomainException
     */
    public function confirmEmailChange(User $user, string $token): void
    {
        $matches = $user->pending_email !== null
            && hash_equals((string) $user->pending_email_hash, hash('sha256', $token))
            && $user->pending_email_expires_at?->isFuture();

        if (! $matches) {
            throw new DomainException(__('That link is not valid any more. Ask for the change again.'));
        }

        if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->getKey())->exists()) {
            throw new DomainException(__('Another account took that address meanwhile.'));
        }

        $previous = (string) $user->email;

        DB::transaction(function () use ($user, $previous): void {
            $user->forceFill([
                'email' => $user->pending_email,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_hash' => null,
                'pending_email_expires_at' => null,
            ])->save();

            $this->audit->logSecurity('account.email_changed', 'User', (string) $user->id, ['from' => $previous], userId: (string) $user->id);
        });

        // The old address is told: if this was not its owner, that is how
        // they find out.
        try {
            Mail::to($previous)->send(new EmailChangedMail(name: (string) $user->name, newEmail: (string) $user->email));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A new password, and every other session ended with the old one.
     *
     * @throws DomainException when the current password is wrong
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, (string) $user->password)) {
            throw new DomainException(__('Your current password is not right.'));
        }

        $user->forceFill([
            'password' => Hash::make($new),
            'remember_token' => Str::random(60),
            // API tokens issued before this stop working (JwtGuard).
            'password_changed_at' => now(),
            ...self::NO_PENDING_EMAIL,
        ])->save();

        // Keeps this session on the new hash, so the sessions auth.session
        // guards elsewhere end and this one does not. It checks the password
        // against the stored one, so it comes after the save.
        Auth::logoutOtherDevices($new);

        $this->audit->logSecurity('account.password_changed', 'User', (string) $user->id, userId: (string) $user->id);
    }

    /**
     * @throws DomainException when the password is wrong
     */
    public function signOutOtherSessions(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new DomainException(__('That password is not right.'));
        }

        Auth::logoutOtherDevices($password);

        $user->forceFill([
            'remember_token' => Str::random(60),
            // API tokens too: "everywhere else" includes them.
            'password_changed_at' => now(),
            // Someone signed out here may have asked for a new address.
            ...self::NO_PENDING_EMAIL,
        ])->save();
    }
}
