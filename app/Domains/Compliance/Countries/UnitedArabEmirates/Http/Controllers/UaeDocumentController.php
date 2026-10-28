<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Pagination\Keyset;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\ResponseData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The organization's Peppol documents, sent and received.
 *
 * The XML is the record the taxpayer keeps, so it is served exactly as it
 * travelled. Another organization's document is a 404.
 */
class UaeDocumentController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly UaeDocuments $documents,
    ) {}

    /**
     * GET /api/compliance/ae/documents?direction=&status=&updated_since=
     *
     * Newest first by page number, or with ?cursor= every document once by
     * when it last changed, as the invoice list (Keyset).
     */
    #[ResponseData(UaeDocuments::SCHEMA, list: true)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'direction' => ['sometimes', Rule::in([PeppolDocument::DIRECTION_IN, PeppolDocument::DIRECTION_OUT])],
            'status' => ['sometimes', 'string', 'max:20'],
        ]);
        $organizationId = $this->tenant->getOrganizationId();
        $since = Keyset::since($request);
        $present = fn (PeppolDocument $document): array => UaeDocuments::metadata($document);

        if (Keyset::requested($request)) {
            return ApiResponse::cursor(Keyset::page(
                $this->documents->listed($organizationId, $filters['direction'] ?? null, $filters['status'] ?? null, $since),
                $request,
                PageSize::fromRequest($request, 15),
            ), $present);
        }

        return ApiResponse::paginated($this->documents->paginate(
            $organizationId,
            $filters['direction'] ?? null,
            $filters['status'] ?? null,
            PageSize::fromRequest($request, 15),
            $since,
        )->through($present));
    }

    /**
     * GET /api/compliance/ae/documents/{document}
     */
    #[ResponseData(UaeDocuments::SCHEMA, under: 'document')]
    public function show(string $document): JsonResponse
    {
        $found = $this->documents->find($this->tenant->getOrganizationId(), $document);

        return ApiResponse::success(['document' => UaeDocuments::metadata($found)]);
    }

    /**
     * GET /api/compliance/ae/documents/{document}/xml
     */
    public function xml(string $document): Response
    {
        $found = $this->documents->find($this->tenant->getOrganizationId(), $document);

        return response((string) $found->xml, 200, UaeDocuments::xmlHeaders($found));
    }
}
