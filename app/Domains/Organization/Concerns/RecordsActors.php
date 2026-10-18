<?php

declare(strict_types=1);

namespace App\Domains\Organization\Concerns;

use App\Domains\Audit\Attribution\Origin;
use Illuminate\Database\Eloquent\Model;

/**
 * Who created, last changed and removed a record, how it arrived, through
 * which licence and in which request (Origin): the person signed in when it
 * happened, or the person whose request queued the job that did it, or
 * nobody for the scheduler or a command.
 *
 * Filled here and nowhere else, and never mass-assignable, so no request
 * can claim to be someone it is not. A model using it keeps created_by and
 * updated_by, and deleted_by where it is soft-deleted, unless it names its
 * own columns in attributionColumns(): a compliance record keeps only who
 * created it, an append-only log only its request id. A service that already
 * knows who acted - a job finishing what a person started - may set a
 * column itself, and it is kept.
 *
 * A removal must go through the model (delete()), not a bulk query, for
 * deleted_by to be written. Bringing a record back clears it.
 */
trait RecordsActors
{
    public static function bootRecordsActors(): void
    {
        static::creating(function (Model $model): void {
            $origin = app(Origin::class);
            $columns = $model->attributionColumns();
            $actor = $origin->actorId();

            foreach (['created_by', 'updated_by'] as $role) {
                if (isset($columns[$role]) && $actor !== null && $model->getAttribute($columns[$role]) === null) {
                    $model->setAttribute($columns[$role], $actor);
                }
            }

            if (isset($columns['created_via']) && $model->getAttribute($columns['created_via']) === null) {
                $model->setAttribute($columns['created_via'], $origin->channel()->value);
            }

            foreach (['license_id' => $origin->licenseId(), 'request_id' => $origin->requestId()] as $role => $value) {
                if (isset($columns[$role]) && $value !== null && $model->getAttribute($columns[$role]) === null) {
                    $model->setAttribute($columns[$role], $value);
                }
            }
        });

        static::updating(function (Model $model): void {
            $columns = $model->attributionColumns();
            $actor = app(Origin::class)->actorId();

            if (isset($columns['updated_by']) && $actor !== null && ! $model->isDirty($columns['updated_by'])) {
                $model->setAttribute($columns['updated_by'], $actor);
            }

            // Brought back: nobody has removed it any more.
            if (isset($columns['deleted_by']) && $model->isDirty('deleted_at') && $model->getAttribute('deleted_at') === null) {
                $model->setAttribute($columns['deleted_by'], null);
            }
        });

        static::deleted(function (Model $model): void {
            $column = $model->attributionColumns()['deleted_by'] ?? null;
            $actor = app(Origin::class)->actorId();

            if ($column !== null && $actor !== null && method_exists($model, 'trashed') && $model->trashed()) {
                $model->forceFill([$column => $actor])->saveQuietly();
            }
        });
    }

    /**
     * The columns this model's table keeps, by what each records: any of
     * created_by, updated_by, deleted_by, created_via, license_id and
     * request_id. A table may keep one under its own name (a payment's
     * recorded_by is who created it).
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by', 'deleted_by' => 'deleted_by'];
    }
}
