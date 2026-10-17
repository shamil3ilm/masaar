<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

/**
 * Access's answer for one feature: allowed or not, why in words a person can
 * act on, the level that decided, and the facts behind it.
 *
 * $code names the reason for code that branches on it, and is stable; the
 * reason is translated. $source is null for an allowed answer no level had
 * to make, and Plan for one a platform admin granted beyond the plan.
 */
final readonly class Decision
{
    /**
     * @param  array<string, mixed>  $detail  the switch, country, licence, status or role it rests on
     */
    public function __construct(
        public Feature $feature,
        public bool $allowed,
        public string $reason,
        public ?Level $source,
        public string $code,
        public array $detail = [],
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function refuse(Feature $feature, Level $source, string $code, string $reason, array $detail = []): self
    {
        return new self($feature, false, $reason, $source, $code, $detail);
    }

    public static function allow(Feature $feature): self
    {
        return new self($feature, true, __('Available.'), null, 'allowed');
    }
}
