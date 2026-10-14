<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a tenant's ICV/PIH chain currently stands.
 *
 * One row per organization, keyed on org_id rather than a surrogate id: the
 * chain has a single head, and a second row would mean two competing ones.
 */
class ChainState extends Model
{
    use BelongsToTenant;

    /**
     * One head per device - head office or a branch with its own
     * certificate - because ZATCA chains per EGS unit.
     */
    protected $table = 'hash_chain_heads';

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $fillable = [
        'device_id',
        'org_id',
        'last_hash',
        'last_icv',
        'last_invoice_id',
        'certificate_id',
        'cert_transition',
    ];

    protected function casts(): array
    {
        return [
            'last_icv' => 'integer',
            'cert_transition' => 'array',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
