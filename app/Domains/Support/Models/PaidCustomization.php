<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Organization\Concerns\TenantScope;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paid work for one customer: what support agreed on its ticket - the work,
 * the price an admin entered, whether it is billed, the features it switches
 * on once released - and the invoice that billed it.
 *
 * Not tenant-scoped: a platform admin, who holds no tenant, writes it, and
 * every query names the organization (PaidCustomizations).
 *
 * @property string $ticket_id
 * @property string $org_id
 * @property string|null $description
 * @property string|null $price
 * @property string|null $currency
 * @property bool $billable
 * @property list<string>|null $features
 * @property string|null $invoice_id
 */
class PaidCustomization extends Model
{
    use HasUuids;
    use RecordsActors;

    protected $fillable = [
        'ticket_id',
        'org_id',
        'description',
        'price',
        'currency',
        'billable',
        'features',
    ];

    /**
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['created_by' => 'created_by', 'updated_by' => 'updated_by'];
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billable' => 'boolean',
            'features' => 'array',
            'billed_at' => 'datetime',
        ];
    }

    /** Agreed: there is a price, or it is done for nothing. */
    public function agreed(): bool
    {
        return ! $this->billable || $this->price !== null;
    }

    public function ticket(): BelongsTo
    {
        // The row names its ticket: the tenant scope, which a platform admin
        // holds no tenant for, has nothing to add.
        return $this->belongsTo(SupportTicket::class, 'ticket_id')->withoutGlobalScope(TenantScope::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
