<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Controllers;

use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\UaeDocuments;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
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
     * GET /api/compliance/ae/documents
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'direction' => ['sometimes', Rule::in([PeppolDocument::DIRECTION_IN, PeppolDocument::DIRECTION_OUT])],
            'status' => ['sometimes', 'string', 'max:20'],
        ]);

        return ApiResponse::paginated($this->documents->paginate(
            $this->tenant->getOrganizationId(),
            $filters['direction'] ?? null,
            $filters['status'] ?? null,
            PageSize::fromRequest($request, 15),
        ));
    }

    /**
     * GET /api/compliance/ae/documents/{document}
     */
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
