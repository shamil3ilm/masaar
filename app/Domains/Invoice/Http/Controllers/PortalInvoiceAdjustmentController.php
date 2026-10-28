<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\AdjustmentKind;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceAdjustments;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Return, exchange or replace" on an issued invoice, from the browser
 * (InvoiceAdjustments): say what happened, see the documents it makes, then
 * make them.
 *
 * The preview is a POST that stores nothing; making them is a second POST
 * behind a confirmation, carrying the same request, so what is made is what
 * was shown. Who may: whoever may issue at the invoice's branch, and not a
 * read-only role; Access answers first, as it does before any issue.
 */
class PortalInvoiceAdjustmentController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly InvoiceAdjustments $adjustments,
        private readonly ComplianceEngine $engine,
        private readonly Access $access,
    ) {}

    public function create(Request $request, string $invoiceId): View|RedirectResponse
    {
        [$invoice, $organization] = $this->original($request, $invoiceId);

        if (($refusal = $this->engine->jurisdictionFor($organization)->adjustments()->refusal($invoice)) !== null) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $refusal);
        }

        return view('portal.invoices.adjustments.create', [
            'invoice' => $invoice,
            'kinds' => AdjustmentKind::requested(),
            'adjustments' => $this->adjustments->of($invoice),
        ]);
    }

    public function preview(Request $request, string $invoiceId): View|RedirectResponse
    {
        [$invoice, $organization] = $this->original($request, $invoiceId);
        $input = $this->input($request);

        try {
            $plan = $this->adjustments->plan($organization, $invoice, $input);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return view('portal.invoices.adjustments.preview', [
            'invoice' => $invoice,
            'plan' => $plan,
            'hidden' => $this->hidden($input),
        ]);
    }

    public function store(Request $request, string $invoiceId): RedirectResponse
    {
        [$invoice, $organization] = $this->original($request, $invoiceId);
        $input = $this->input($request);
        $decision = $this->access->decide($organization, $request->user(), Feature::IssueInvoices);

        if (! $decision->allowed) {
            return redirect()->route('portal.invoices.show', $invoiceId)->with('error', __('Nothing was made: :reason', ['reason' => $decision->reason]));
        }

        try {
            $made = $this->adjustments->apply($organization, $invoice, $input, $request->user(), integration: false);
        } catch (DomainException $e) {
            return redirect()->route('portal.invoices.adjustments.create', $invoiceId)->withInput($input)->with('error', __('Nothing was made: :reason', ['reason' => $e->getMessage()]));
        }

        $said = array_map(fn (array $document): string => match ($document['status']) {
            'pending_approval' => __(':number waits for approval by another owner or admin.', ['number' => $this->name($document['invoice'])]),
            'draft' => __(':number is a draft for you to check and issue.', ['number' => $this->name($document['invoice'])]),
            default => __(':number issued.', ['number' => $this->name($document['invoice'])]),
        }, $made['documents']);

        return redirect()->route('portal.invoices.show', $invoiceId)
            ->with('success', implode(' ', [__('Recorded: :kind.', ['kind' => $made['adjustment']->kind->label()]), ...$said]));
    }

    /**
     * The invoice, for someone who may issue at its branch.
     *
     * @return array{0: Invoice, 1: Organization}
     */
    private function original(Request $request, string $invoiceId): array
    {
        $organizationId = (string) $request->attributes->get(PortalTenant::ORG_ID);

        abort_if(MemberRole::readOnly($request->user()?->roleIn($organizationId)), 403, __('Your role in this organization is read-only.'));

        try {
            $invoice = $this->invoices->findWithLines($organizationId, $invoiceId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        abort_if(Gate::denies('issue', $invoice), 403, __('You cannot issue invoices at this branch.'));

        return [$invoice, $this->tenant->getOrganization() ?? abort(404)];
    }

    /**
     * The request, with the lines left at nothing dropped and the items left
     * blank on the form dropped.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $kind = AdjustmentKind::tryFrom((string) $request->input('kind'));
        $discount = $kind === AdjustmentKind::Discount;

        // A line is returned with a quantity, or ticked for a discount; an
        // item is one with a description. What the form sends that the
        // choice does not use is left out, so a value typed for another
        // choice is never made.
        $request->merge([
            'lines' => array_values(array_filter((array) $request->input('lines', []), fn ($line): bool => is_array($line) && filled($line['line'] ?? null)
                && ($discount ? filled($line['chosen'] ?? null) : filled($line['quantity'] ?? null) && (float) $line['quantity'] > 0))),
            'items' => array_values(array_filter((array) $request->input('items', []), fn ($item): bool => is_array($item) && filled($item['description'] ?? null))),
        ]);

        $input = $request->validate([...InvoiceAdjustments::rules(), 'discount_by' => ['nullable', 'in:amount,percent']]);
        $kind = AdjustmentKind::from($input['kind']);
        $percent = ($input['discount_by'] ?? 'amount') === 'percent';

        return array_filter([
            'kind' => $input['kind'],
            'lines' => $kind->returnsLines() || $discount
                ? array_map(fn (array $line): array => $discount ? ['line' => $line['line'], 'chosen' => '1'] : ['line' => $line['line'], 'quantity' => $line['quantity'] ?? null], $input['lines'] ?? [])
                : null,
            'discount_amount' => $discount && ! $percent ? ($input['discount_amount'] ?? null) : null,
            'discount_percent' => $discount && $percent ? ($input['discount_percent'] ?? null) : null,
            'discount_by' => $discount && $percent ? 'percent' : null,
            'items' => $kind->replaces() ? ($input['items'] ?? null) : null,
            'buyer' => $kind === AdjustmentKind::BuyerCorrection ? ($input['buyer'] ?? null) : null,
            'note' => $input['note'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * The request as hidden fields, name => value, for the confirming POST.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function hidden(array $input): array
    {
        $fields = [];

        foreach (Arr::dot($input) as $key => $value) {
            if ($value === null || is_array($value)) {
                continue;
            }

            $parts = explode('.', (string) $key);
            $fields[array_shift($parts).implode('', array_map(fn (string $part): string => '['.$part.']', $parts))] = (string) $value;
        }

        return $fields;
    }

    private function name(Invoice $invoice): string
    {
        $invoice = $invoice->fresh() ?? $invoice;
        $label = match ($invoice->document_type?->value) {
            'credit_note' => __('Credit note'),
            'debit_note' => __('Debit note'),
            default => __('Invoice'),
        };

        return $invoice->invoice_number ? $label.' '.$invoice->invoice_number : $label;
    }
}
