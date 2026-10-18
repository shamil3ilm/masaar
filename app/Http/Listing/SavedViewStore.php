<?php

declare(strict_types=1);

namespace App\Http\Listing;

/**
 * Where a person's saved views are kept.
 *
 * Per person, per place they work - an organization's id, or 'admin' in the
 * console - and per list. Behind an interface because it is a per-person
 * preference: the layered settings store can keep it instead by binding
 * another implementation, and no list changes.
 */
interface SavedViewStore
{
    /** How many views one person keeps on one list. */
    public const LIMIT = 20;

    /**
     * Theirs on the list, by name.
     *
     * @return list<SavedView>
     */
    public function for(string $userId, string $scope, string $list): array;

    /**
     * Keep a view under a name, replacing theirs of the same name.
     *
     * @throws \DomainException when they already keep LIMIT others
     */
    public function save(string $userId, string $scope, string $list, string $name, string $query): SavedView;

    /** Forget one of theirs; false when it is not theirs or not there. */
    public function delete(string $userId, string $scope, string $id): bool;
}
