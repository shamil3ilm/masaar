<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Http\Controllers;

use App\Domains\Compliance\Exceptions\IssuanceRefusedException;
use App\Domains\Compliance\Peppol\Http\Requests\CreateUaeInvoiceRequest;
use App\Domains\Compliance\Peppol\Services\UaeDocuments;
use App\Domains\Compliance\Peppol\Services\UaeInvoiceIssuer;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * UAE invoices: drafted, issued and sent as Peppol documents.
 *
 * Drafting is the platform's own InvoiceDrafter, shared with Saudi invoices;
 * only the request's rules and jurisdiction_data are the UAE's. Issuing is
 * UaeInvoiceIssuer. Every lookup is inside the caller's organization, so
 * another's invoice is a 404.
 */
class UaeInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceDrafter $drafter,
        private readonly InvoiceFinder $invoices,
        private readonly UaeInvoiceIssuer $issuer,
        private readonly UaeDocuments $documents,
    ) {}

    /**
     * POST /api/compliance/ae/invoices
     */
    public function store(CreateUaeInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->drafter->draft(
            $request->invoice(),
            (string) $this->tenant->getOrganizationId(),
            countryFields: $request->jurisdictionFields(),
        );

        return ApiResponse::created(['invoice' => $invoice->load('lines')], 'Invoice created');
    }

    /**
     * POST /api/compliance/ae/invoices/{invoice}/send
     *
     * Issues the draft and queues its Peppol document. 422 with every problem
     * when the document would be refused, and the invoice stays a draft. A
     * second call sends nothing new and reports the document already made.
     */
    public function send(string $invoice): JsonResponse
    {
        $found = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $invoice);

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
        ], 'Invoice issued');
    }

    /**
     * GET /api/compliance/ae/invoices/{invoice}/status
     *
     * Where the invoice's Peppol document stands; the document fields are
     * null until it has been sent.
     */
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
            'errors' => $document?->errors ?? [],
            'last_error' => $document?->last_error,
        ]);
    }
}
