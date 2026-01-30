<?php

declare(strict_types=1);

namespace App\Domains\Auth\DTOs;

/**
 * Data transfer object for login credentials.
 *
 * Immutable value object representing authentication input.
 */
final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            email: $data['email'],
            password: $data['password'],
        );
    }
}
