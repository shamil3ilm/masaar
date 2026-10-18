<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Who created, last changed and removed a record: the person signed in
 * when it happened, or nobody for a command or a queue.
 *
 * Filled here and nowhere else, and never mass-assignable, so no request
 * can claim to be someone it is not. A model using it keeps created_by and
 * updated_by, and deleted_by where it is soft-deleted; a removal must go
 * through the model (delete()), not a bulk query, for deleted_by to be
 * written.
 */
trait RecordsActors
{
    public static function bootRecordsActors(): void
    {
        static::creating(function (Model $model): void {
            $actor = auth()->id();

            if ($actor !== null) {
                $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $actor);
                $model->setAttribute('updated_by', $actor);
            }
        });

        static::updating(function (Model $model): void {
            $actor = auth()->id();

            if ($actor !== null) {
                $model->setAttribute('updated_by', $actor);
            }

            // Brought back: nobody has removed it any more.
            if ($model->isDirty('deleted_at') && $model->getAttribute('deleted_at') === null) {
                $model->setAttribute('deleted_by', null);
            }
        });

        static::deleted(function (Model $model): void {
            $actor = auth()->id();

            if ($actor !== null && method_exists($model, 'trashed') && $model->trashed()) {
                $model->forceFill(['deleted_by' => $actor])->saveQuietly();
            }
        });
    }
}
