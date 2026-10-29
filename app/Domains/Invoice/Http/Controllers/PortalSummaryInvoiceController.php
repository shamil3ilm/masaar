<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\SummaryInvoices;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Delivery notes and the month's summary invoice, from the browser
 * (SummaryInvoices): record what was delivered, then gather a customer's
 * month into one draft invoice, which is issued from its own page.
 */
class PortalSummaryInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SummaryInvoices $summaries,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $invoiced = in_array($request->query('show'), ['open', 'invoiced'], true) ? $request->query('show') === 'invoiced' : null;
        $month = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $notes = $this->summaries->list($organizationId, null, $invoiced)->withQueryString();

        return view('portal.invoices.summaries', [
            'notes' => $notes,
            'show' => $request->query('show'),
            'month' => $month->toDateString(),
            'deadline' => $this->summaries->issueBy($organization, $month->endOfMonth()),
            // The date each delivery not yet invoiced must be on a summary by.
            'issueBy' => $notes->getCollection()->whereNull('invoice_id')->mapWithKeys(fn ($note): array => [
                $note->id => $this->summaries->issueBy($organization, CarbonImmutable::parse($note->delivered_on)->endOfMonth()),
            ])->all(),
        ]);
    }

    public function storeNote(Request $request): RedirectResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        // The form's blank rows are not lines.
        $request->merge(['lines' => array_values(array_filter((array) $request->input('lines', []), fn (mixed $line): bool => is_array($line) && filled($line['description'] ?? null)))]);
        $note = $this->summaries->record($organization, $request->validate($this->summaries->noteRules($organization)));

        return redirect()->route('portal.summaries.index')->with('success', __('Delivery note :number recorded.', ['number' => $note->number]));
    }

    public function removeNote(Request $request, string $noteId): RedirectResponse
    {
        $note = $this->summaries->find((string) $request->attributes->get(PortalTenant::ORG_ID), $noteId) ?? abort(404);

        try {
            $this->summaries->remove($note);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('Delivery note :number removed.', ['number' => $note->number]));
    }

    /** Draft the summary for a customer's month and open it, to check and issue. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'month' => ['required', 'date'],
            'issue_date' => ['nullable', 'date'],
        ]);
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $from = CarbonImmutable::parse($validated['month'])->startOfMonth();
        $issueDate = isset($validated['issue_date']) ? CarbonImmutable::parse($validated['issue_date']) : CarbonImmutable::today();

        try {
            $invoice = $this->summaries->draft($organization, (string) $validated['customer_id'], $from, $from->endOfMonth()->startOfDay(), $issueDate);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        $redirect = redirect()->route('portal.invoices.show', $invoice->id)->with('success', __('Summary invoice drafted from :count delivery notes. Check it and issue it by :date.', [
            'count' => count($this->summaries->on($invoice)),
            'date' => $this->summaries->issueBy($organization, $from->endOfMonth())->toDateString(),
        ]));
        $late = $this->summaries->lateness($organization, $from->endOfMonth()->startOfDay(), $issueDate);

        return $late === null ? $redirect : $redirect->with('warning', $late);
    }
}
