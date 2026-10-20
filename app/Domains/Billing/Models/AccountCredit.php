<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit an organization holds against its next invoice. Read and written
 * through AccountCredits only.
 *
 * Not tenant-scoped: the platform grants it and invoicing redeems it, and
 * AccountCredits names the organization in every query.
 *
 * @property string $org_id
 * @property string $source
 * @property string|null $source_id
 * @property string $kind
 * @property string|null $amount
 * @property string|null $percent
 * @property string|null $currency
 * @property string $description
 * @property string $status
 * @property Carbon|null $expires_at
 * @property string|null $redeemed_invoice_id
 * @property string|null $redeemed_amount
 */
class AccountCredit extends Model
{
    use HasUuids;
    use RecordsActors;

    public const AVAILABLE = 'available';

    public const REDEEMED = 'redeemed';

    public const REVERSED = 'reversed';

    protected $fillable = [
        'org_id',
        'source',
        'source_id',
        'kind',
        'amount',
        'percent',
        'currency',
        'description',
        'status',
        'expires_at',
    ];

    /**
     * A financial record: who granted it, and who reversed it.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'redeemed_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** Available and not past its expiry: what invoicing may use. */
    public function usable(): bool
    {
        return $this->status === self::AVAILABLE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
