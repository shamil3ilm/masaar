<?php

declare(strict_types=1);

namespace App\Domains\Auth\DTOs;

/**
 * Data transfer object representing an authentication token response.
 *
 * Contains the JWT and its metadata.
 */
final readonly class AuthToken
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
    ) {}

    public static function fromJwt(string $token, int $ttl): self
    {
        return new self(
            accessToken: $token,
            tokenType: 'bearer',
            expiresIn: $ttl,
        );
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
        ];
    }
}
