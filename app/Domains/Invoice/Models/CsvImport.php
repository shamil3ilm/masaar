<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A CSV file of invoices, customers or products brought in through the
 * portal, and what came of it (CsvImports).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $branch_id
 * @property string $kind
 * @property string $status
 * @property string|null $file_path
 * @property string $file_name
 * @property int $file_size
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_invalid
 * @property int $rows_imported
 * @property list<array{row: int, field: string, message: string}>|null $problems
 * @property list<array<string, string>>|null $preview
 * @property string|null $failure
 * @property Carbon|null $checked_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $file_removed_at
 */
class CsvImport extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    /** Being read and checked: by the request for a small file, a job for a large one. */
    public const CHECKING = 'checking';

    /** Checked, its problems listed, waiting to be confirmed. */
    public const CHECKED = 'checked';

    /** Confirmed: its records are being saved. */
    public const IMPORTING = 'importing';

    public const IMPORTED = 'imported';

    /** The file could not be read at all. */
    public const FAILED = 'failed';

    protected $fillable = [
        'kind',
        'file_name',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'rows_total' => 'integer',
            'rows_valid' => 'integer',
            'rows_invalid' => 'integer',
            'rows_imported' => 'integer',
            'problems' => 'array',
            'preview' => 'array',
            'checked_at' => 'datetime',
            'finished_at' => 'datetime',
            'file_removed_at' => 'datetime',
        ];
    }

    /** Confirmed rows can be saved: checked, with at least one row that passed. */
    public function canImport(): bool
    {
        return $this->status === self::CHECKED && $this->rows_valid > 0 && $this->file_path !== null;
    }

    /** Still being worked on, so the page offers to look again. */
    public function busy(): bool
    {
        return in_array($this->status, [self::CHECKING, self::IMPORTING], true);
    }
}
