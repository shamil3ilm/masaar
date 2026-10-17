<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Models;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link in a tenant's ICV/PIH chain.
 *
 * Append-only: each row records the hash of an invoice and the hash of the one
 * before it, so the sequence can be re-walked and a gap or edit shows up as a
 * break. Nothing here is updated after insert.
 */
class ChainEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hash_chain_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'org_id',
        'device_id',
        'invoice_id',
        'invoice_hash',
        'previous_hash',
        'icv',
        'certificate_id',
        'cert_transition',
    ];

    protected function casts(): array
    {
        return [
            'icv' => 'integer',
            'cert_transition' => 'array',
        ];
    }

    /**
     * An entry belongs to a device's chain. One written without naming the
     * device - a fixture, a backfill - is head office's, as every entry was
     * before devices had chains; left null it would sit outside every chain,
     * and the (device_id, icv) unique index treats nulls as distinct.
     */
    protected static function booted(): void
    {
        static::saving(function (ChainEntry $entry): void {
            $entry->device_id ??= $entry->org_id;
        });
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
