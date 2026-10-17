<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceLinks;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Response;

/**
 * An invoice opened from its link - the QR code on it, or one the seller
 * sent - without signing in.
 *
 * The token is the only key, and opens only its own invoice. A link that
 * never existed, was revoked, or whose invoice may no longer be handed over
 * is the same 404, so a guess learns nothing. Search engines are told not to
 * index it; the route limits how fast anyone can try.
 */
class InvoiceLinkController extends Controller
{
    private const NO_INDEX = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly InvoiceLinks $links,
        private readonly InvoicePdf $pdf,
    ) {}

    public function show(string $token): Response
    {
        [$invoice, $organization] = $this->open($token);

        return response()->view('invoice-link.show', [
            'invoice' => $invoice,
            'organization' => $organization,
            'token' => $token,
        ], 200, self::NO_INDEX);
    }

    public function pdf(string $token): Response
    {
        [$invoice, $organization] = $this->open($token);

        try {
            $pdf = $this->pdf->render($invoice, $organization);
        } catch (DomainException) {
            abort(404);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->pdf->fileName($invoice).'"',
            'X-Content-Type-Options' => 'nosniff',
        ] + self::NO_INDEX);
    }

    /**
     * @return array{0: Invoice, 1: Organization}
     */
    private function open(string $token): array
    {
        $link = $this->links->open($token) ?? abort(404);
        $invoice = $this->links->invoice($link) ?? abort(404);
        $organization = $this->links->organization($link) ?? abort(404);

        try {
            $this->pdf->assertShareable($invoice, $organization);
        } catch (DomainException) {
            abort(404);
        }

        return [$invoice, $organization];
    }
}
