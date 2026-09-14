<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Services;

use App\Domains\Compliance\FTA\Models\FtaSubmission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Looks up UAE FTA submissions.
 *
 * FtaSubmission uses BelongsToTenant, so a lookup by id finds only the
 * current tenant's submission.
 */
class SubmissionFinder
{
    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): FtaSubmission
    {
        return FtaSubmission::findOrFail($id);
    }

    /**
     * An organization's submissions, newest first, each with its invoice's id
     * and number.
     */
    public function paginate(string $organizationId, int $perPage): LengthAwarePaginator
    {
        return FtaSubmission::where('org_id', $organizationId)
            ->with('invoice:id,invoice_number')
            ->latest()
            ->paginate($perPage);
    }
}
