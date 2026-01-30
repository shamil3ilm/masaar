<?php

declare(strict_types=1);

namespace App\Domains\Auth\Contracts;

use App\Domains\Auth\DTOs\AuthToken;
use App\Domains\Auth\DTOs\LoginData;

/**
 * Contract for user authentication operations.
 *
 * JWT represents a User. Organization context is secondary.
 */
interface AuthenticatesUsers
{
    /**
     * Authenticate a user and return a token.
     */
    public function attempt(LoginData $credentials): ?AuthToken;

    /**
     * Refresh an existing token.
     */
    public function refresh(): AuthToken;

    /**
     * Invalidate the current token.
     */
    public function logout(): void;

    /**
     * Get the currently authenticated user.
     */
    public function user(): mixed;
}
