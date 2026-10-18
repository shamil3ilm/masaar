<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

/**
 * What an action would change, as the request shows it: one line saying
 * what it is, the record it acts on, and the values before and after.
 *
 * $targetUserId names the person the action is about, when it is about one:
 * nobody approves a change to their own access.
 */
final readonly class Preview
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public string $summary,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public array $before = [],
        public array $after = [],
        public ?string $targetUserId = null,
    ) {}
}
