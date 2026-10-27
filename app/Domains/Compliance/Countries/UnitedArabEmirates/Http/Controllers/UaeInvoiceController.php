<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests\CreateUaeInvoiceRequest;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\IssueDeadline;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeDrafts;
use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\NoteIssuing;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\ResponseData;
use Illuminate\Http\JsonResponse;

/**
 * UAE invoices: drafted, issued and sent as Peppol documents.
 *
 * Drafting is the platform's own InvoiceDrafter, shared by every country;
 * only the request's rules and jurisdiction_data are the UAE's. Issuing is
 * InvoiceIssuer. Every lookup is inside the caller's organization, so
 * another's invoice is a 404.
 */
class UaeInvoiceController extends Controller
{
    /** What status() answers, for the OpenAPI description (#[ResponseData]). */
    public const STATUS = [
        'type' => 'object',
        'required' => ['invoice_id', 'invoice_status', 'document_id', 'status', 'errors', 'reporting_status', 'reporting_errors', 'warnings'],
        'properties' => [
            'invoice_id' => ['type' => 'string', 'format' => 'uuid'],
            'invoice_status' => ['type' => 'string'],
            'document_id' => ['type' => ['string', 'null'], 'description' => 'Null until the invoice is sent'],
            'status' => ['type' => ['string', 'null'], 'description' => 'Delivery over the network'],
            'message_id' => ['type' => ['string', 'null']],
            'mls_code' => ['type' => ['string', 'null']],
            'errors' => ['type' => 'array', 'items' => UaeDocuments::ERROR],
            'last_error' => ['type' => ['string', 'null']],
            'reporting_status' => ['type' => ['string', 'null'], 'description' => 'The report to the authority'],
            'authority_reference' => ['type' => ['string', 'null']],
            'reporting_errors' => ['type' => 'array', 'items' => UaeDocuments::ERROR],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly UaeDrafts $drafts,
        private readonly InvoiceFinder $invoices,
        private readonly InvoiceIssuer $issuer,
        private readonly UaeDocuments $documents,
        private readonly NoteIssuing $notes,
    ) {}

    /**
     * POST /api/compliance/ae/invoices
     */
    public function store(CreateUaeInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->drafts->draft($request, (string) $this->tenant->getOrganizationId());

        return ApiResponse::created(['invoice' => $invoice->load('lines')], 'Invoice created');
    }

    /**
     * POST /api/compliance/ae/invoices/{invoice}/send
     *
     * Issues the draft and queues its Peppol document. 422 with every problem
     * when the document would be refused, and the invoice stays a draft. A
     * second call sends nothing new and reports the document already made;
     * an invoice issued without sending (the pipeline's auto_submit false)
     * is queued by it, once. warnings says when it was issued late.
     */
    public function send(string $invoice): JsonResponse
    {
        $found = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoice);

        // A draft note the organization holds for approval waits for one
        // (NoteIssuing), as it does in the portal: 202 with the request.
        if ($found->status === InvoiceStatus::Draft && ($held = $this->notes->holdIntegration($found, $this->tenant->getOrganization(), request()->user())) !== null) {
            return ApiResponse::success(['invoice_id' => $found->id, 'approval' => $held], __('The note waits for approval in the organization. It is issued once approved.'), 202);
        }

        try {
            $document = $this->issuer->issue($found);
        } catch (IssuanceRefusedException $e) {
            return ApiResponse::error(__('The invoice was not issued.'), 422, ['problems' => $e->problems]);
        }

        return ApiResponse::success([
            'invoice_id' => $found->id,
            'invoice_number' => $found->invoice_number,
            'invoice_status' => $found->status->value,
            'document_id' => $document->id,
            'status' => $document->status,
            'reporting_status' => $document->reporting_status,
            'warnings' => IssueDeadline::warnings($document),
        ], 'Invoice issued');
    }

    /**
     * GET /api/compliance/ae/invoices/{invoice}/status
     *
     * Where the invoice's Peppol document stands; the document fields are
     * null until it has been sent.
     */
    #[ResponseData(self::STATUS)]
    public function status(string $invoice): JsonResponse
    {
        $organizationId = $this->tenant->getOrganizationId();
        $found = $this->invoices->find($organizationId, $invoice);
        $document = $this->documents->outboundFor($organizationId, (string) $found->id);

        return ApiResponse::success([
            'invoice_id' => $found->id,
            'invoice_status' => $found->status->value,
            'document_id' => $document?->id,
            'status' => $document?->status,
            'message_id' => $document?->asp_message_id,
            'mls_code' => $document?->mls_code,
            // Each {code, severity, field, message}.
            'errors' => $document?->errorList('errors') ?? [],
            'last_error' => $document?->last_error,
            // The report to the authority, confirmed apart from delivery
            // (UAE Electronic Invoicing Guidelines v1.1, p10-11).
            'reporting_status' => $document?->reporting_status,
            'authority_reference' => $document?->authority_reference,
            'reporting_errors' => $document?->errorList('reporting_errors') ?? [],
            'warnings' => IssueDeadline::warnings($document),
        ]);
    }
}
