<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\SavedListView;
use App\Http\Listing\Lists;
use App\Http\Listing\SavedView;
use App\Http\Listing\SavedViewStore;
use DomainException;
use Illuminate\Support\Str;

/**
 * Saved views, in a table of their own until the layered settings store
 * keeps per-person preferences: binding another SavedViewStore is all that
 * moving them takes.
 *
 * Every query names the person and the scope, so one person's views are
 * never another's, and an organization's are not read from another.
 */
class SavedListViews implements SavedViewStore
{
    public function for(string $userId, string $scope, string $list): array
    {
        return SavedListView::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('list', $list)
            ->orderBy('name')
            ->get(['id', 'name', 'query'])
            ->map(fn (SavedListView $view): SavedView => new SavedView((string) $view->id, (string) $view->name, (string) $view->query))
            ->all();
    }

    public function save(string $userId, string $scope, string $list, string $name, string $query): SavedView
    {
        $existing = SavedListView::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('list', $list)
            ->where('name', $name)
            ->first();

        if ($existing === null && SavedListView::query()->where('user_id', $userId)->where('scope', $scope)->where('list', $list)->count() >= self::LIMIT) {
            throw new DomainException(__('You already keep :count views on this list. Delete one to save another.', ['count' => self::LIMIT]));
        }

        $view = $existing ?? new SavedListView([
            'user_id' => $userId,
            'scope' => $scope,
            'org_id' => $scope === Lists::ADMIN || ! Str::isUuid($scope) ? null : $scope,
            'list' => $list,
            'name' => $name,
        ]);

        $view->query = $query;
        $view->save();

        return new SavedView((string) $view->id, $name, $query);
    }

    public function delete(string $userId, string $scope, string $id): bool
    {
        return SavedListView::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->delete() > 0;
    }
}
