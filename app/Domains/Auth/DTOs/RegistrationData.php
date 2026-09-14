<?php

declare(strict_types=1);

namespace App\Domains\Auth\DTOs;

/**
 * Validated input for opening an account.
 *
 * Carries the plain password only as far as the hash; never log it.
 */
final readonly class RegistrationData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
        );
    }
}
