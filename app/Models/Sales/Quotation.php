<?php

declare(strict_types=1);

namespace App\Models\Sales;

use App\Models\Branch;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasStateMachine;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use BelongsToOrganization, HasUuid, HasStateMachine, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'quotation_number',
        'customer_id',
        'customer_name',
        'customer_email',
        'billing_address',
        'shipping_address',
        'quotation_date',
        'valid_until',
        'currency_code',
        'exchange_rate',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_amount',
        'total',
        'status',
        'salesperson_id',
        'notes',
        'terms_and_conditions',
        'reference',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'version' => 'integer',
        ];
    }

    protected function getStateField(): string
    {
        return 'status';
    }

    protected function getStateTransitions(): array
    {
        return [
            self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_DECLINED],
            self::STATUS_SENT => [self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_EXPIRED],
            self::STATUS_ACCEPTED => [self::STATUS_CONVERTED],
            self::STATUS_DECLINED => [],
            self::STATUS_EXPIRED => [self::STATUS_SENT], // Can be reactivated
            self::STATUS_CONVERTED => [],
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_order');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function canBeConverted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * Recalculate totals from lines.
     */
    public function recalculateTotals(): void
    {
        $subtotal = $this->lines()->sum('subtotal');
        $taxAmount = $this->lines()->sum('tax_amount');

        $discountAmount = 0;
        if ($this->discount_type === 'percentage' && $this->discount_value > 0) {
            $discountAmount = bcmul((string) $subtotal, bcdiv((string) $this->discount_value, '100', 6), 4);
        } elseif ($this->discount_type === 'fixed' && $this->discount_value > 0) {
            $discountAmount = $this->discount_value;
        }

        $total = bcsub(bcadd((string) $subtotal, (string) $taxAmount, 4), (string) $discountAmount, 4);

        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SENT]);
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SENT])
            ->whereBetween('valid_until', [now(), now()->addDays($days)]);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}
