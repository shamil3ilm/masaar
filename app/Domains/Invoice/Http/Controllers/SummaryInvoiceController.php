<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\SummaryInvoices;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery notes and the month's summary invoice through the API
 * (SummaryInvoices). The summary is drafted; it is issued like any draft.
 *
 * GET/POST /api/delivery-notes, DELETE /api/delivery-notes/{id},
 * POST /api/invoices/summary; the partner API's /v1/... the same.
 */
class SummaryInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SummaryInvoices $summaries,
    ) {}

    /** ?customer_id= one customer's; ?invoiced=0 or 1 the open or invoiced ones. */
    public function notes(Request $request): JsonResponse
    {
        $request->validate(['customer_id' => ['nullable', 'uuid'], 'invoiced' => ['nullable', 'boolean']]);
        $page = $this->summaries->list(
            (string) $this->tenant->getOrganizationId(),
            $request->query('customer_id'),
            $request->has('invoiced') ? $request->boolean('invoiced') : null,
        );

        return ApiResponse::success(['delivery_notes' => $page->items(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()]]);
    }

    public function storeNote(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $note = $this->summaries->record($organization, $request->validate($this->summaries->noteRules($organization)));

        return ApiResponse::created(['delivery_note' => $note], 'Delivery note recorded. It is not a tax document: a summary invoice will list it.');
    }

    public function removeNote(string $id): JsonResponse
    {
        $note = $this->summaries->find((string) $this->tenant->getOrganizationId(), $id) ?? abort(404);
        $this->summaries->remove($note);

        return ApiResponse::success(null, 'Delivery note removed');
    }

    /**
     * Draft the summary invoice of a customer's open delivery notes in a
     * period within one calendar month.
     *
     * POST /api/invoices/summary {customer_id, from, to, issue_date?, due_date?}
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        $organization = $this->tenant->getOrganization() ?? abort(404);
        $to = CarbonImmutable::parse($validated['to']);
        $issueDate = isset($validated['issue_date']) ? CarbonImmutable::parse($validated['issue_date']) : CarbonImmutable::today();
        $invoice = $this->summaries->draft(
            $organization,
            (string) $validated['customer_id'],
            CarbonImmutable::parse($validated['from']),
            $to,
            $issueDate,
            isset($validated['due_date']) ? CarbonImmutable::parse($validated['due_date']) : null,
        );
        $late = $this->summaries->lateness($organization, $to, $issueDate);
        $drafted = [
            'invoice' => $invoice->load('lines'),
            'delivery_notes' => $this->summaries->on($invoice),
            'issue_by' => $this->summaries->issueBy($organization, $to)->toDateString(),
            // Past the law's day: drafted all the same, and said.
            'late' => $late !== null,
        ];

        if ($late !== null) {
            $drafted['warnings'] = [$late];
        }

        return ApiResponse::created($drafted, 'Summary invoice drafted. Issue it as any draft.');
    }
}
