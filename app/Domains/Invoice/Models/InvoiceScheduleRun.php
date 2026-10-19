<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of a schedule and the invoice made for it. Its row is the
 * period's claim: (schedule_id, period) is unique, so a period is invoiced
 * once however many runners reach it (migration 1260).
 */
class InvoiceScheduleRun extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'schedule_id',
        'period',
        'period_start',
        'period_end',
        'status',
        'invoice_id',
        'attempts',
        'next_attempt_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScheduleRunStatus::class,
            'period' => 'integer',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
        ];
    }

    /**
     * Who made it - the runner, or a person skipping the period - and who
     * last changed it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    /**
     * @return BelongsTo<InvoiceSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InvoiceSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
