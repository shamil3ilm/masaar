<?php

declare(strict_types=1);

namespace App\Models\CRM;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\Sales\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'organization_id',
        'opportunity_number',
        'name',
        'description',
        'contact_id',
        'lead_id',
        'account_name',
        'pipeline_stage_id',
        'probability',
        'amount',
        'currency_code',
        'expected_revenue',
        'expected_close_date',
        'actual_close_date',
        'status',
        'lost_reason',
        'won_reason',
        'assigned_to',
        'branch_id',
        'lead_source_id',
        'quotation_id',
        'sales_order_id',
        'notes',
        'tags',
        'competitors',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'amount' => 'decimal:4',
            'expected_revenue' => 'decimal:4',
            'expected_close_date' => 'date',
            'actual_close_date' => 'date',
            'tags' => 'array',
            'competitors' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Opportunity $opportunity) {
            // Auto-calculate expected revenue
            if ($opportunity->amount && $opportunity->probability) {
                $opportunity->expected_revenue = bcmul(
                    (string) $opportunity->amount,
                    bcdiv((string) $opportunity->probability, '100', 4),
                    4
                );
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'related');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isWon(): bool
    {
        return $this->status === self::STATUS_WON;
    }

    public function isLost(): bool
    {
        return $this->status === self::STATUS_LOST;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_WON, self::STATUS_LOST], true);
    }

    public function getDaysOpen(): int
    {
        $endDate = $this->actual_close_date ?? now();
        return $this->created_at->diffInDays($endDate);
    }

    public function getDaysUntilClose(): ?int
    {
        if (!$this->expected_close_date || $this->isClosed()) {
            return null;
        }

        return now()->diffInDays($this->expected_close_date, false);
    }

    public function isOverdue(): bool
    {
        if (!$this->expected_close_date || $this->isClosed()) {
            return false;
        }

        return $this->expected_close_date->isPast();
    }

    public function updateStage(PipelineStage $stage): void
    {
        $this->pipeline_stage_id = $stage->id;
        $this->probability = $stage->probability;

        if ($stage->is_won) {
            $this->status = self::STATUS_WON;
            $this->actual_close_date = now();
        } elseif ($stage->is_lost) {
            $this->status = self::STATUS_LOST;
            $this->actual_close_date = now();
        }

        $this->save();
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeWon($query)
    {
        return $query->where('status', self::STATUS_WON);
    }

    public function scopeLost($query)
    {
        return $query->where('status', self::STATUS_LOST);
    }

    public function scopeClosingThisMonth($query)
    {
        return $query->where('status', self::STATUS_OPEN)
            ->whereBetween('expected_close_date', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OPEN)
            ->where('expected_close_date', '<', now());
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeInStage($query, int $stageId)
    {
        return $query->where('pipeline_stage_id', $stageId);
    }

    public function scopeForContact($query, int $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    public function scopeClosedBetween($query, $startDate, $endDate)
    {
        return $query->whereIn('status', [self::STATUS_WON, self::STATUS_LOST])
            ->whereBetween('actual_close_date', [$startDate, $endDate]);
    }
}
