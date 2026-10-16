<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\DTOs\RegistrationData;
use App\Domains\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Opens accounts.
 *
 * An account and the audit entry recording how it came to exist are written
 * together, so there is never one without the other.
 */
class AccountRegistrar
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Send the verification link again, to an account that has not used it.
     * Nothing happens for an address with no account or a verified one.
     */
    public function resendVerification(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->hasVerifiedEmail()) {
            return;
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Create an active account and record its registration.
     */
    public function register(RegistrationData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'status' => 'active',
            ]);

            $this->audit->logAuth('register', $user->id);

            return $user;
        });
    }
}
