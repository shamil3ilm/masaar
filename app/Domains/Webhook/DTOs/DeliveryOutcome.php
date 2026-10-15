<?php

declare(strict_types=1);

namespace App\Domains\Webhook\DTOs;

/**
 * What one delivery attempt came to.
 *
 * Retryable means the receiver never gave an answer about the event: it was
 * unreachable, timed out, was busy (408, 429) or failed (5xx). A refusal - any
 * other status, or an endpoint the platform will not call - is an answer, and
 * sending the same body again would get the same one.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public bool $delivered,
        public bool $retryable,
    ) {}

    public static function delivered(): self
    {
        return new self(true, false);
    }

    public static function refused(): self
    {
        return new self(false, false);
    }

    public static function unanswered(): self
    {
        return new self(false, true);
    }

    public static function forStatus(int $status): self
    {
        return match (true) {
            $status >= 200 && $status < 300 => self::delivered(),
            $status === 408, $status === 429, $status >= 500 => self::unanswered(),
            default => self::refused(),
        };
    }
}
