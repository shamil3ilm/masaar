<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Looks up an organization's Peppol documents.
 *
 * Every lookup names the organization as well as passing through the tenant
 * scope, so another organization's document is not found even where the
 * scope stands down. Listings leave the XML out: it is fetched one document
 * at a time.
 */
class UaeDocuments
{
    /** Everything about a document but its XML. */
    public const METADATA = [
        'id', 'invoice_id', 'direction', 'country', 'customization_id', 'profile_id', 'document_type',
        'asp_message_id', 'status', 'mls_code', 'errors', 'sender_endpoint', 'receiver_endpoint',
        'attempts', 'last_error', 'created_at', 'updated_at',
    ];

    public function paginate(?string $organizationId, ?string $direction, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->ofOrganization($organizationId)
            ->select(self::METADATA)
            ->when($direction !== null, fn (Builder $q) => $q->where('direction', $direction))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(?string $organizationId, string $id): PeppolDocument
    {
        return $this->ofOrganization($organizationId)->findOrFail($id);
    }

    /**
     * The document an invoice was sent as, if it has been.
     */
    public function outboundFor(?string $organizationId, string $invoiceId): ?PeppolDocument
    {
        return $this->ofOrganization($organizationId)
            ->where('invoice_id', $invoiceId)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(PeppolDocument $document): array
    {
        return $document->only(self::METADATA);
    }

    private function ofOrganization(?string $organizationId): Builder
    {
        return PeppolDocument::query()->where('org_id', $organizationId);
    }
}
