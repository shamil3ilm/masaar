<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\ReceiptPrinting;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use GdImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * An issued invoice for a receipt printer, for POS software and ERPs that
 * drive the printer themselves: an image at the printer's dots, or ESC/POS.
 *
 * Arabic is drawn into the image, so a printer with no Arabic code page
 * prints it. The printer's dots and resolution are the invoice's branch's
 * (its till's), or what the caller names.
 */
class InvoiceReceiptController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly InvoiceFinder $invoices,
        private readonly ReceiptPrinting $printing,
    ) {}

    /**
     * GET /api/invoices/{id}/receipt-image?paper=80mm|58mm&dots=576&dpi=203
     *
     * A black-and-white PNG one pixel per printer dot. 409 for an invoice
     * that may not be given to the buyer yet.
     */
    public function image(Request $request, string $id): Response|JsonResponse
    {
        $options = $request->validate($this->rules());
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $id);

        try {
            $image = $this->render($request, $invoice, $options);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return response($this->printing->png($image), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->printing->fileName($invoice, 'png').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /api/invoices/{id}/escpos?paper=80mm|58mm&dots=576&dpi=203&cut=1&drawer=2|5
     *
     * The bytes to send to the printer as they are: the receipt as a raster
     * image (GS v 0), then a partial cut unless cut=0, then a pulse to open
     * the cash drawer on pin 2 or 5 when drawer is given.
     */
    public function escPos(Request $request, string $id): Response|JsonResponse
    {
        $options = $request->validate([
            ...$this->rules(),
            'cut' => ['nullable', 'boolean'],
            'drawer' => ['nullable', Rule::in(['2', '5'])],
        ]);
        $invoice = $this->invoices->findWithLines($this->tenant->getOrganizationId(), $id);

        try {
            $image = $this->render($request, $invoice, $options);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        $drawer = isset($options['drawer']) ? (int) $options['drawer'] : null;

        return response($this->printing->escPos($image, (bool) ($options['cut'] ?? true), $drawer), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$this->printing->fileName($invoice, 'bin').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'paper' => ['nullable', Rule::in([PaperSize::Roll80->value, PaperSize::Roll58->value])],
            // Whole bytes of dots, from a 2-inch head to a 4-inch one at 300 dpi.
            'dots' => ['nullable', 'integer', 'between:64,1280', 'multiple_of:8'],
            'dpi' => ['nullable', 'integer', 'between:100,600'],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function render(Request $request, Invoice $invoice, array $options): GdImage
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);

        return $this->printing->image(
            $invoice,
            $organization,
            $request->user()?->id,
            $options['paper'] ?? null,
            isset($options['dots']) ? (int) $options['dots'] : null,
            isset($options['dpi']) ? (int) $options['dpi'] : null,
        );
    }
}
