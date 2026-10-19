<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Models;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's invoice to one buyer that repeats on a schedule: a
 * school's terms, a subscription, a monthly service.
 *
 * Each period is a run (InvoiceScheduleRun) that drafts the invoice from
 * template, through the organization's country's rules as an invoice
 * entered by hand is, and issues it when auto_issue says so. See
 * migration 1250 for what each column holds.
 */
class InvoiceSchedule extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'org_id',
        'branch_id',
        'customer_id',
        'source_invoice_id',
        'name',
        'frequency',
        'terms',
        'start_date',
        'end_date',
        'occurrences',
        'auto_issue',
        'due_days',
        'template',
        'status',
        'next_period',
        'next_run_on',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ScheduleFrequency::class,
            'status' => ScheduleStatus::class,
            'terms' => 'array',
            'template' => 'array',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'next_run_on' => 'date:Y-m-d',
            'occurrences' => 'integer',
            'next_period' => 'integer',
            'due_days' => 'integer',
            'auto_issue' => 'boolean',
        ];
    }

    /**
     * Who made and last changed it, how it arrived and through which
     * licence, as for the invoices it makes.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return [
            'created_by' => 'created_by',
            'updated_by' => 'updated_by',
            'created_via' => 'created_via',
            'license_id' => 'license_id',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * Its periods so far, the first first.
     *
     * @return HasMany<InvoiceScheduleRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(InvoiceScheduleRun::class, 'schedule_id')->orderBy('period');
    }

    /** The buyer every run bills, as the template names them. */
    public function buyerName(): string
    {
        $template = (array) $this->template;

        return (string) ($template['buyer_name'] ?? $template['buyer_name_ar'] ?? '');
    }
}
