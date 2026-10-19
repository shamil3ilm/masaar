<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\DTOs\PrintSample;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\InvoicePrintView;
use App\Domains\Invoice\Templates\InvoiceTemplate;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\PrintedColumns;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Editing an invoice template: the platform's default from the admin
 * console, an organization's or one of its branches' from its portal.
 *
 * The two differ in whose template it is, who may change it and which
 * country's invoice the preview draws, and nothing else. A save keeps only
 * what differs from the level above; a reset goes back to it; each is
 * recorded as a customization change (InvoiceTemplates). The preview and the
 * sample PDF draw a made-up invoice (Presentation::sampleInvoice) with the
 * form's values, saved or not.
 */
abstract class InvoiceTemplateController extends Controller
{
    public function __construct(
        protected readonly InvoiceTemplates $templates,
        private readonly InvoicePrintView $printView,
        private readonly InvoicePdf $pdf,
    ) {}

    /**
     * Whose template the request edits: its level, its scope and its
     * organization (null for the platform's).
     *
     * @return array{level: Level, scope: Scope, organization: Organization|null}
     */
    abstract protected function owner(Request $request): array;

    /** The country whose invoice the preview draws, and its fields. */
    abstract protected function country(?Organization $organization): ?Presentation;

    /** The route name prefix: admin.invoice-template or portal.invoice-template. */
    abstract protected function route(): string;

    abstract protected function view(): string;

    /**
     * What the page adds for its level: the branches to choose from.
     *
     * @return array<string, mixed>
     */
    protected function extra(Request $request, array $owner): array
    {
        return [];
    }

    /** The query that keeps a branch chosen across the page's links. */
    protected function query(Request $request): array
    {
        return [];
    }

    public function edit(Request $request): View
    {
        $owner = $this->owner($request);
        $country = $this->country($owner['organization']);
        $fields = $country?->printedFields() ?? [];

        return view($this->view(), [
            'values' => $this->templates->values($owner['scope']),
            'own' => $this->templates->own($owner['level'], $owner['scope']),
            'level' => $owner['level'],
            'template' => $this->templates->preview($owner['scope'], [], $owner['organization']),
            'layouts' => TemplateLayout::cases(),
            'languages' => $this->templates->languages($owner['organization']),
            'fields' => $fields,
            'locks' => PrintedColumns::locks($fields),
            'prints' => $country?->sampleInvoice($owner['organization']) !== null,
            'route' => $this->route(),
            'query' => $this->query($request),
            ...$this->extra($request, $owner),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);

        $this->templates->save($owner['level'], $owner['scope'], $this->input($request), $owner['organization'], (string) Auth::id());

        return redirect()->route($this->route().'.edit', $this->query($request))->with('success', __('Invoice template saved.'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);

        $this->templates->reset($owner['level'], $owner['scope'], (string) Auth::id());

        return redirect()->route($this->route().'.edit', $this->query($request))->with('success', __('Invoice template reset to the one it inherits.'));
    }

    /** The sample invoice laid out with the form's values, on the paper asked for. */
    public function preview(Request $request): Response|View
    {
        [$country, $sample, $template] = $this->sample($request);

        if ($sample === null) {
            return response(__('Invoices here reach the buyer as e-invoices: there is no printed copy to preview.'), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return view('portal.invoices.print', [
            ...$this->printView->sample($country, $sample->invoice, $sample->seller, $this->paper($request), $template),
            'preview' => true,
        ]);
    }

    /** The same, as the PDF a buyer would be given - without the XML and the seal. */
    public function samplePdf(Request $request): Response
    {
        [$country, $sample, $template] = $this->sample($request);

        abort_if($sample === null, 404);

        $paper = $this->paper($request);

        return response($this->pdf->sample($country, $sample->invoice, $sample->seller, $paper, $template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="sample-invoice-'.$paper->value.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{0: Presentation|null, 1: PrintSample|null, 2: InvoiceTemplate}
     */
    private function sample(Request $request): array
    {
        $owner = $this->owner($request);
        $country = $this->country($owner['organization']);

        return [
            $country,
            $country?->sampleInvoice($owner['organization']),
            $this->templates->preview($owner['scope'], $this->input($request), $owner['organization']),
        ];
    }

    private function paper(Request $request): PaperSize
    {
        return PaperSize::tryFrom((string) $request->query('paper')) ?? PaperSize::A4;
    }

    /**
     * The form's fields as the template keeps them. The colour is the
     * picker's only when its box is ticked: a colour picker always sends a
     * colour, and an unticked box means the theme's.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = $request->only(array_values(array_diff(InvoiceTemplate::FIELDS, ['accent'])));

        if ($request->has('accent_custom')) {
            $input['accent'] = $request->boolean('accent_custom') ? (string) $request->input('accent_color') : null;
        }

        return $input;
    }
}
