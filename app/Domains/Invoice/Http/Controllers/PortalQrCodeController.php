<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\BusinessQrCode;
use App\Domains\Invoice\Services\BusinessQrCodes;
use App\Domains\Invoice\Services\InvoiceExtraQr;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Invoice\Services\InvoicePdf;
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
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $organizationId = (string) $organization->id;

        return view('portal.qr-codes', [
            'admin' => $request->user()?->roleIn($organizationId) === 'admin',
            'codes' => $this->codes->all($organizationId)->map(fn (BusinessQrCode $code): array => [
                'code' => $code,
                'svg' => $this->qr->svg($this->codes->payload($code), BusinessQrCodes::ENCODING),
            ]),
            'links' => $this->links->active($organizationId)->map(fn ($link): array => [
                'link' => $link,
                'url' => $this->links->url($link),
            ]),
            'papers' => PaperSize::cases(),
            'choices' => $this->extraQr->choices($organization),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request, __('Only an administrator of this organization can change its QR codes.'));
        $kind = (string) $request->validate(['kind' => ['required', Rule::in(BusinessQrCode::KINDS)]])['kind'];

        $this->codes->create($organizationId, $kind, $request->validateWithBag($kind, $this->codes->rules($kind)));

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
        $number = (string) $request->validateWithBag('link', ['invoice_number' => ['required', 'string', 'max:100']])['invoice_number'];
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

        $this->links->create($invoice, $request->user()?->id);

        return redirect()->route('portal.qr-codes.index')->with('success', __('Invoice link created.'));
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
