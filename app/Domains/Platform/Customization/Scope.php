<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

/**
 * Where a value is asked for: the platform alone, an organization, a branch
 * of it, a till of that branch, a person in it - or a person across every
 * organization, for what is theirs wherever they work (their language).
 *
 * Each level resolves only when the scope names it: a scope with no branch
 * skips the branch level, one with no person stops at the organization.
 */
final readonly class Scope
{
    private function __construct(
        public ?string $organizationId = null,
        public ?string $branchId = null,
        public ?string $userId = null,
        public ?string $tillId = null,
    ) {}

    public static function platform(): self
    {
        return new self;
    }

    public static function organization(string $organizationId): self
    {
        return new self($organizationId);
    }

    public static function branch(string $organizationId, string $branchId): self
    {
        return new self($organizationId, $branchId);
    }

    /** One till of a branch: its printer is its own. */
    public static function till(string $organizationId, ?string $branchId, string $tillId): self
    {
        return new self($organizationId, $branchId, null, $tillId);
    }

    /** A person in an organization, at a branch and a till when they are known. */
    public static function member(string $organizationId, string $userId, ?string $branchId = null, ?string $tillId = null): self
    {
        return new self($organizationId, $branchId, $userId, $tillId);
    }

    /** A person wherever they are, in no organization in particular. */
    public static function person(string $userId): self
    {
        return new self(userId: $userId);
    }

    public function has(Level $level): bool
    {
        return match ($level) {
            Level::Platform => true,
            Level::Organization => $this->organizationId !== null,
            Level::Branch => $this->organizationId !== null && $this->branchId !== null,
            Level::Till => $this->organizationId !== null && $this->tillId !== null,
            Level::User => $this->userId !== null,
        };
    }

    /**
     * The row a level's value is kept under, or null when this scope does
     * not reach it. A person's value is theirs in one organization unless
     * the definition keeps it across all of them.
     */
    public function holder(Level $level, bool $acrossOrganizations = false): ?string
    {
        if (! $this->has($level)) {
            return null;
        }

        return match ($level) {
            Level::Platform => 'platform',
            Level::Organization => 'organization:'.$this->organizationId,
            Level::Branch => 'branch:'.$this->branchId,
            Level::Till => 'till:'.$this->tillId,
            Level::User => match (true) {
                $acrossOrganizations => 'user:'.$this->userId,
                $this->organizationId === null => null,
                default => 'member:'.$this->organizationId.':'.$this->userId,
            },
        };
    }
}
