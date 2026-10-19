<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One word about a Peppol document's transmission: a move the platform
 * made, a provider's callback, or a status lookup's answer, applied or not.
 *
 * Append-only. The data on an e-invoice's issuance, transmission and receipt
 * must be retained (Electronic Invoicing Guidelines v1.1, p12), so a row is
 * never changed and never deleted; PeppolDocument keeps the current state,
 * which DocumentHistory derives from these again.
 */
class PeppolDocumentEvent extends Model
{
    use RecordsActors;

    /** Written once and never changed. */
    public const UPDATED_AT = null;

    public const TRACK_STATE = 'state';

    protected $fillable = [
        'document_id',
        'source',
        'track',
        'status',
        'reporting_status',
        'applied',
        'mls_code',
        'asp_message_id',
        'authority_reference',
        'errors',
        'payload_digest',
        'occurred_at',
    ];

    /**
     * No actor of its own - the person is on the invoice; the request it
     * arrived in.
     *
     * @return array<string, string>
     */
    public function attributionColumns(): array
    {
        return ['request_id' => 'request_id'];
    }

    protected function casts(): array
    {
        return [
            'applied' => 'boolean',
            'errors' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('A Peppol document event is never changed: the transmission record is append-only.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('A Peppol document event is never deleted: the transmission record must be retained.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PeppolDocument::class, 'document_id');
    }
}
