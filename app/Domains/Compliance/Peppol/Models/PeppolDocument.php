<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Models;

use App\Domains\Organization\Concerns\BelongsToTenant;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Peppol document, sent ('out') or received ('in').
 *
 * The XML is kept as it travelled: it is the record the taxpayer must
 * retain, and what the service provider's status and message level
 * response (mls_code) refer to.
 */
class PeppolDocument extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IN = 'in';

    protected $fillable = [
        'org_id',
        'invoice_id',
        'direction',
        'country',
        'customization_id',
        'profile_id',
        'document_type',
        'xml',
        'asp_message_id',
        'status',
        'mls_code',
        'errors',
        'sender_endpoint',
        'receiver_endpoint',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
