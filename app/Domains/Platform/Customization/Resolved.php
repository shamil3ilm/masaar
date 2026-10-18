<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

/**
 * A customization's answer for one scope: the value, and where it came from.
 *
 * $source is the level that set the value, or null for the shipped default.
 * $own is the value at the level asked about ($at), null when that level
 * sets nothing and inherits. $lockedBy names a level above that locked its
 * value, so nothing at $at can change it.
 */
final readonly class Resolved
{
    /**
     * @param  array<string, mixed>  $levels  level value => the value set there, for every level that set one
     */
    public function __construct(
        public Definition $definition,
        public mixed $value,
        public ?Level $source,
        public Level $at,
        public mixed $own,
        public ?Level $lockedBy,
        public array $levels,
    ) {}

    /** Whether the value comes from above the level asked about. */
    public function inherited(): bool
    {
        return $this->source !== $this->at;
    }

    /** "The platform's default", "Set here", "Locked by the organization": where the value comes from. */
    public function sourceLabel(): string
    {
        if ($this->lockedBy !== null && $this->lockedBy !== $this->at) {
            return __('Locked by: :level', ['level' => $this->lockedBy->label()]);
        }

        if ($this->source === null) {
            return __('Shipped default');
        }

        return $this->inherited()
            ? __('Inherited from: :level', ['level' => $this->source->label()])
            : __('Set here');
    }
}
