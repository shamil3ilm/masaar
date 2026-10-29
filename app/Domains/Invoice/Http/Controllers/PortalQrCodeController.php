<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\BusinessQrCode;
use App\Domains\Invoice\Services\BusinessQrCodes;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Support\QrCodeRenderer;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's QR codes: its own (a contact card, payment details, a
 * link), links that open one invoice, and which extra code its invoices
 * carry on each paper.
 *
 * Every member can download them and give an invoice a link; the
 * organization's own codes and what prints on its invoices are its
 * administrators'.
 */
class PortalQrCodeController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BusinessQrCodes $codes,
        private readonly InvoiceLinks $links,
        private readonly InvoiceExtraQr $extraQr,
        private readonly QrCodeRenderer $qr,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $organizationId = (string) $organization->id;

        return view('portal.qr-codes', [
            'admin' => MemberRole::administers($request->user()?->roleIn($organizationId)),
            'codes' => $this->codes->all($organizationId)->map(fn (BusinessQrCode $code): array => [
                'code' => $code,
                'svg' => $this->qr->svg($this->codes->payload($code), BusinessQrCodes::ENCODING),
            ]),
            'links' => $this->links->active($organizationId)->map(fn ($link): array => [
                'link' => $link,
                'url' => $this->links->url($link),
            ]),
            'lifetimes' => InvoiceLinks::LIFETIMES,
            'defaultDays' => $organization->invoice_link_days,
            'papers' => PaperSize::cases(),
            'choices' => $this->extraQr->choices($organization),
            'removed' => $this->codes->removed($organizationId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request, __('Only an administrator of this organization can change its QR codes.'));
        $kind = (string) $request->validate(['kind' => ['required', Rule::in(BusinessQrCode::KINDS)]])['kind'];

        // The caption is printed under the code on the invoice: in Arabic
        // where the organization's country requires it.
        $rules = [
            ...$this->codes->rules($kind),
            'label' => [...$this->jurisdictions->forRules($this->tenant->getOrganization())->languageRules()->arabicTextRules(), 'max:80'],
        ];

        $this->codes->create($organizationId, $kind, $request->validateWithBag($kind, $rules));

        return redirect()->route('portal.qr-codes.index')->with('success', __('QR code created.'));
    }

    public function destroy(Request $request, string $qrCodeId): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its QR codes.'));

        $this->codes->delete((string) $organization->id, $qrCodeId);
        // A deleted code is no longer printed on any paper.
        $this->extraQr->setChoices($organization, $this->extraQr->choices($organization));

        return redirect()->route('portal.qr-codes.index')->with('success', __('QR code deleted.'));
    }

    /** A removed code, back as it was. Not printed again until chosen. */
    public function restore(Request $request, string $qrCodeId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request, __('Only an administrator of this organization can change its QR codes.'));

        $this->codes->restore($organizationId, $qrCodeId) ?? abort(404);

        return redirect()->route('portal.qr-codes.index')->with('success', __('QR code restored.'));
    }

    public function download(Request $request, string $qrCodeId, string $format): Response
    {
        $code = $this->codes->find($this->portalOrganizationId($request), $qrCodeId) ?? abort(404);

        return $this->file($this->codes->payload($code), $format, 'qr-'.$code->kind);
    }

    /** What prints beside the country's QR on each paper. */
    public function updateInvoices(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change its QR codes.'));
        $validated = $request->validate(['papers' => ['array'], 'papers.*' => ['nullable', 'string', 'max:40']]);

        $this->extraQr->setChoices($organization, $validated['papers'] ?? []);

        return redirect()->route('portal.qr-codes.index')->with('success', __('The QR codes on invoices are saved.'));
    }

    /** A link that opens one issued invoice, found by its number. */
    public function storeLink(Request $request, InvoicePdf $pdf): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $validated = $request->validateWithBag('link', [
            'invoice_number' => ['required', 'string', 'max:100'],
            'lifetime' => $this->lifetimeRules(),
        ]);
        $number = (string) $validated['invoice_number'];
        $invoice = $this->links->invoiceNumbered((string) $organization->id, trim($number));

        if ($invoice === null) {
            return back()->withInput()->withErrors(['invoice_number' => __('No invoice has that number.')], 'link');
        }

        // Only an invoice that may be handed to the buyer: the link opens it
        // for anyone holding it.
        try {
            $pdf->assertShareable($invoice, $organization);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['invoice_number' => $e->getMessage()], 'link');
        }

        // Not chosen, the organization's default.
        $days = ($validated['lifetime'] ?? null) === null
            ? $organization->invoice_link_days
            : $this->days((string) $validated['lifetime']);

        $this->links->create($invoice, $request->user()?->id, $days);

        return redirect()->route('portal.qr-codes.index')->with('success', __('Invoice link created.'));
    }

    /** How long the organization's new links last unless chosen otherwise. */
    public function updateLinkLifetime(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can change how long its links last.'));
        $validated = $request->validate(['lifetime' => ['required', ...$this->lifetimeRules()]]);

        $this->links->setDefaultDays($organization, $this->days((string) $validated['lifetime']));

        return redirect()->route('portal.qr-codes.index')->with('success', __('New invoice links will last as chosen.'));
    }

    public function revokeLink(Request $request, string $linkId): RedirectResponse
    {
        $this->links->revoke($this->portalOrganizationId($request), $linkId);

        return redirect()->route('portal.qr-codes.index')->with('success', __('Invoice link revoked. It no longer opens the invoice.'));
    }

    public function downloadLink(Request $request, string $linkId, string $format): Response
    {
        $link = $this->links->find($this->portalOrganizationId($request), $linkId) ?? abort(404);
        abort_if($link->revoked_at !== null, 404);

        return $this->file($this->links->url($link), $format, 'invoice-link');
    }

    /**
     * @return list<mixed>
     */
    private function lifetimeRules(): array
    {
        return ['nullable', Rule::in(['never', ...array_map('strval', InvoiceLinks::LIFETIMES)])];
    }

    /** A lifetime as chosen: days, or null for never. */
    private function days(string $lifetime): ?int
    {
        return $lifetime === 'never' ? null : (int) $lifetime;
    }

    private function file(string $payload, string $format, string $name): Response
    {
        $image = $format === 'svg'
            ? $this->qr->svg($payload, BusinessQrCodes::ENCODING)
            : $this->qr->png($payload, BusinessQrCodes::ENCODING);

        return response($image, 200, [
            'Content-Type' => $format === 'svg' ? 'image/svg+xml' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$name.'.'.$format.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
