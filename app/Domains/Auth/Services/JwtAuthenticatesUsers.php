<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\DTOs\AuthToken;
use App\Domains\Auth\DTOs\LoginData;
use Tymon\JWTAuth\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

final class JwtAuthenticatesUsers implements AuthenticatesUsers
{
    public function __construct(
        private readonly JWTAuth $jwt
    ) {}

    public function attempt(LoginData $credentials): ?AuthToken
    {
        try {
            if (! $token = $this->jwt->attempt([
                'email' => $credentials->email,
                'password' => $credentials->password,
            ])) {
                return null;
            }

            return AuthToken::fromJwt(
                token: $token,
                ttl: $this->jwt->factory()->getTTL() * 60
            );
        } catch (JWTException) {
            return null;
        }
    }

    public function refresh(): AuthToken
    {
        $token = $this->jwt->refresh();

        return AuthToken::fromJwt(
            token: $token,
            ttl: $this->jwt->factory()->getTTL() * 60
        );
    }

    public function logout(): void
    {
        $this->jwt->invalidate();
    }

    public function user(): mixed
    {
        return $this->jwt->user();
    }
}
