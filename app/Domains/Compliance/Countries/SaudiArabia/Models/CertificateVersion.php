<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One production certificate a device has held.
 *
 * The secret material is not here: the certificate, its API secret and its
 * private key stay in CredentialStore, encrypted. This is what can be queried
 * - which certificate, which version, valid when, and whether it is the one
 * the device signs with now.
 *
 * Never deleted. A superseded or revoked version is the record of how the
 * documents it signed were stamped, and a superseded one still files them.
 */
class CertificateVersion extends Model
{
    use BelongsToTenant;

    /** The certificate the device signs new documents with. */
    public const ACTIVE = 'active';

    /** Replaced by a renewal. Still files what it signed. */
    public const SUPERSEDED = 'superseded';

    /** Withdrawn. Files nothing. */
    public const REVOKED = 'revoked';

    protected $table = 'device_certificates';

    protected $fillable = [
        'org_id',
        'device_id',
        'version',
        'certificate_id',
        'serial_number',
        'issuer',
        'valid_from',
        'valid_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
