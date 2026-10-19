<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Models;

use App\Domains\Compliance\Networks\Peppol\Exceptions\DocumentRetainedException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspError;
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
        // Issued past its country's deadline, as the country's module decides.
        'issued_late',
        // The report to the authority, a track of its own beside status
        // (DocumentStatus).
        'reporting_status',
        'authority_reference',
        'reporting_errors',
        'reporting_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'reporting_errors' => 'array',
            'reporting_changed_at' => 'datetime',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'issued_late' => 'boolean',
        ];
    }

    /**
     * A stored document is never deleted through the application, as an
     * issued invoice is not (Invoice's deleting guard). The organization
     * keeps it too: peppol_documents.org_id restricts on delete, so an
     * organization with documents cannot be deleted below this guard either.
     * A bulk query-builder delete bypasses model events, and nothing does one.
     */
    protected static function booted(): void
    {
        static::deleting(function (PeppolDocument $document): void {
            throw DocumentRetainedException::for((string) $document->id);
        });
    }

    /**
     * The document's errors on one track, each as {code, severity, field,
     * message}. Rows from before errors were structured hold strings; they
     * are read the same way a provider's string is (AspError::from).
     *
     * @param  'errors'|'reporting_errors'  $column
     * @return list<array{code: string|null, severity: string, field: string|null, message: string}>
     */
    public function errorList(string $column = 'errors'): array
    {
        return AspError::toArrays(AspError::listFrom((array) ($this->getAttribute($column) ?? [])));
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
