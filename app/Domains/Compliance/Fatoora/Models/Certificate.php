<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CSID a taxpayer has signed with, and the ICV span it covers.
 *
 * The table is certificate_lineage because rows are never edited in place:
 * a renewal supersedes its predecessor and both stay, so an invoice signed
 * two certificates ago can still be traced to the key that signed it.
 */
class Certificate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'certificate_lineage';

    protected $fillable = [
        'org_id',
        'certificate_id',
        'cert_serial',
        'certificate_hash',
        'issuer',
        'valid_from',
        'valid_to',
        'activated_at',
        'revoked_at',
        'first_icv',
        'last_icv',
        'status',
        'superseded_by',
        'transition_reason',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'first_icv' => 'integer',
            'last_icv' => 'integer',
        ];
    }

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const SUPERSEDED = 'superseded';

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The certificate currently signing, of which a tenant has at most one.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }
}
