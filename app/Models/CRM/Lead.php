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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToOrganization, HasUuid, SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_UNQUALIFIED = 'unqualified';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_LOST = 'lost';

    public const RATING_HOT = 'hot';
    public const RATING_WARM = 'warm';
    public const RATING_COLD = 'cold';

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'organization_id',
        'lead_number',
        'title',
        'lead_type',
        'company_name',
        'industry',
        'website',
        'employee_count',
        'annual_revenue',
        'contact_name',
        'contact_title',
        'email',
        'phone',
        'mobile',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'lead_source_id',
        'source_details',
        'assigned_to',
        'branch_id',
        'status',
        'lost_reason',
        'lead_score',
        'rating',
        'estimated_value',
        'currency_code',
        'converted_contact_id',
        'converted_opportunity_id',
        'converted_at',
        'converted_by',
        'description',
        'notes',
        'tags',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_count' => 'integer',
            'annual_revenue' => 'decimal:2',
            'lead_score' => 'integer',
            'estimated_value' => 'decimal:4',
            'converted_at' => 'datetime',
            'tags' => 'array',
        ];
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function convertedOpportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'converted_opportunity_id');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'related');
    }

    public function getDisplayName(): string
    {
        if ($this->lead_type === self::TYPE_COMPANY && $this->company_name) {
            return $this->company_name;
        }
        return $this->contact_name;
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isLost(): bool
    {
        return $this->status === self::STATUS_LOST;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_QUALIFIED,
        ], true);
    }

    public function canBeConverted(): bool
    {
        return $this->status === self::STATUS_QUALIFIED;
    }

    public function getAge(): int
    {
        return $this->created_at->diffInDays(now());
    }

    public function scopeNew($query)
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_QUALIFIED,
        ]);
    }

    public function scopeConverted($query)
    {
        return $query->where('status', self::STATUS_CONVERTED);
    }

    public function scopeLost($query)
    {
        return $query->where('status', self::STATUS_LOST);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeFromSource($query, int $sourceId)
    {
        return $query->where('lead_source_id', $sourceId);
    }

    public function scopeWithRating($query, string $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeHot($query)
    {
        return $query->where('rating', self::RATING_HOT);
    }

    public function scopeCreatedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}
