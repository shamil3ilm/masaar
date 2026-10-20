<?php

declare(strict_types=1);

namespace App\Domains\Support\DTOs;

use App\Domains\Support\Models\FeatureBoardItem;

/**
 * A board item as one company sees it: how many companies want it, and
 * whether this one asked for it, backs it, and - for one person - follows
 * it. Nothing in it names another company.
 *
 * $requests is the number of companies whose own request is in it, which
 * only the console shows beside the demand.
 */
final readonly class BoardStanding
{
    public function __construct(
        public FeatureBoardItem $item,
        public string $categoryName,
        public int $demand,
        public int $requests,
        public bool $askedByUs = false,
        public bool $backedByUs = false,
        public bool $following = false,
    ) {}

    public function following(bool $following): self
    {
        return new self($this->item, $this->categoryName, $this->demand, $this->requests, $this->askedByUs, $this->backedByUs, $following);
    }
}
