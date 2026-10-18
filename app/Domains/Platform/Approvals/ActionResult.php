<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

/**
 * What an action did: the sentence to show, and anything the screen shows
 * once and keeps nowhere - a licence's new secret, say.
 */
final readonly class ActionResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $message,
        public array $data = [],
    ) {}
}
