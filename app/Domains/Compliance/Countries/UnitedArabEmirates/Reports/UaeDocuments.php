<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        'attempts', 'last_error', 'reporting_status', 'authority_reference', 'reporting_errors',
        'reporting_changed_at', 'document_uuid', 'provider_reference', 'created_at', 'updated_at',
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
     * Documents other suppliers sent the organization, newest first, with
     * their XML: the portal lists each one by what its XML says.
     */
    public function received(?string $organizationId, int $perPage): LengthAwarePaginator
    {
        return $this->ofOrganization($organizationId)
            ->where('direction', PeppolDocument::DIRECTION_IN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * One received document; a sent one is not found here.
     *
     * @throws ModelNotFoundException
     */
    public function findReceived(?string $organizationId, string $id): PeppolDocument
    {
        return $this->ofOrganization($organizationId)
            ->where('direction', PeppolDocument::DIRECTION_IN)
            ->findOrFail($id);
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
     * Where the organization's e-invoices are, for the dashboard: sent ones
     * by how far delivery got and, apart, by their report to the authority
     * (the provider confirms the two separately, UAE Electronic Invoicing
     * Guidelines v1.1, p10-11); received ones in all. One grouped count,
     * so the dashboard costs a query however many there are.
     *
     * A document to a reserved endpoint is never exchanged (UAE Electronic
     * Invoicing Guidelines v1.1, p23, p26, p28), so it is counted apart, not
     * as awaiting a delivery; so is one issued before the organization's
     * e-invoicing start date, which is kept and never sent.
     *
     * @return array{awaiting: int, delivered: int, rejected: int, failed: int, received: int, reported: int, report_failed: int, not_exchanged: int, not_transmitted: int}
     */
    public function counts(?string $organizationId): array
    {
        $counts = ['awaiting' => 0, 'delivered' => 0, 'rejected' => 0, 'failed' => 0, 'received' => 0, 'reported' => 0, 'report_failed' => 0, 'not_exchanged' => 0, 'not_transmitted' => 0];

        $rows = $this->ofOrganization($organizationId)
            ->select('direction', 'status', 'reporting_status')
            ->selectRaw('count(*) as total')
            ->groupBy('direction', 'status', 'reporting_status')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $key = $row->direction === PeppolDocument::DIRECTION_IN ? 'received' : match ($row->status) {
                DocumentStatus::DELIVERED => 'delivered',
                DocumentStatus::REJECTED => 'rejected',
                DocumentStatus::FAILED => 'failed',
                DocumentStatus::NOT_EXCHANGED => 'not_exchanged',
                DocumentStatus::NOT_TRANSMITTED => 'not_transmitted',
                default => 'awaiting',
            };
            $counts[$key] += (int) $row->total;

            if ($row->direction === PeppolDocument::DIRECTION_OUT && in_array($row->reporting_status, [DocumentStatus::REPORTED, DocumentStatus::REPORT_FAILED], true)) {
                $counts[$row->reporting_status] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * The organization's latest sent e-invoices, newest first, without XML.
     *
     * @return Collection<int, PeppolDocument>
     */
    public function recentSent(?string $organizationId, int $limit): Collection
    {
        return $this->ofOrganization($organizationId)
            ->select(self::METADATA)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * How a document's XML is served: as a download, exactly as it travelled,
     * and never sniffed into something a browser would render.
     *
     * @return array<string, string>
     */
    public static function xmlHeaders(PeppolDocument $document): array
    {
        return [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="peppol-'.$document->direction.'-'.$document->id.'.xml"',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(PeppolDocument $document): array
    {
        // Errors each as {code, severity, field, message}, whatever form a
        // row from before that holds.
        return [
            ...$document->only(self::METADATA),
            'errors' => $document->errorList('errors'),
            'reporting_errors' => $document->errorList('reporting_errors'),
        ];
    }

    private function ofOrganization(?string $organizationId): Builder
    {
        return PeppolDocument::query()->where('org_id', $organizationId);
    }
}
