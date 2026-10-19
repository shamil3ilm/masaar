<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplatedReceipt;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Support\EscPos;
use DomainException;
use GdImage;

/**
 * An issued invoice for a receipt printer driven directly, not through the
 * browser: as an image at the printer's dots, or as ESC/POS commands.
 *
 * The printer is the invoice's branch's - its till - unless the caller names
 * the dots and resolution itself; the roll is the one asked for, else the
 * preferred paper when that is a roll, else 80 mm.
 */
class ReceiptPrinting
{
    /** The common receipt printer's resolution. */
    public const DPI = 203;

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly PrintPreferences $preferences,
        private readonly PrintAssets $assets,
        private readonly ReceiptImage $images,
        private readonly EscPos $escPos,
        private readonly InvoiceExtraQr $extraQr,
        private readonly InvoiceTemplates $templates,
    ) {}

    /**
     * @throws DomainException when the invoice may not be given out yet
     */
    public function image(Invoice $invoice, Organization $organization, ?string $userId, ?string $paper = null, ?int $dots = null, ?int $dpi = null): GdImage
    {
        $country = $this->jurisdictions->forRules($organization)->presentation();
        $country->assertShareable($invoice);

        $roll = $this->roll($organization, $invoice, $userId, $paper);
        $printer = $this->preferences->receiptPrinter((string) $organization->id, $invoice->branch_id, $invoice->device_id);
        $dpi ??= $printer['dpi'] ?? self::DPI;
        $dots ??= $printer['dots'] ?? (int) $roll->printerDots($dpi);
        // The logo fits within two thirds of the paper, so it never crowds
        // the edges.
        $logo = $this->assets->logoImage((string) $organization->id, intdiv($dots * 2, 3));

        // The organization's template: its language order and its own lines,
        // in black and white as the head prints them.
        $template = $this->templates->for($organization, $invoice->branch_id);

        return $this->images->render(
            TemplatedReceipt::apply($country->receipt($invoice, $organization), $template),
            $roll,
            $dots,
            $dpi,
            $logo,
            $template->businessQr ? $this->extraQr->for($invoice, $organization, $roll, $userId) : null,
        );
    }

    public function png(GdImage $image): string
    {
        return $this->images->png($image);
    }

    /**
     * @param  int|null  $drawer  the cash drawer's pin, 2 or 5; null to leave it shut
     */
    public function escPos(GdImage $image, bool $cut = true, ?int $drawer = null): string
    {
        return $this->escPos->print($image, $cut, $drawer);
    }

    public function fileName(Invoice $invoice, string $extension): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($invoice->invoice_number ?? $invoice->id));

        return trim((string) $number, '-').'-receipt.'.$extension;
    }

    private function roll(Organization $organization, Invoice $invoice, ?string $userId, ?string $paper): PaperSize
    {
        $chosen = $this->preferences->paper($organization, $invoice->branch_id, $userId, $paper, $invoice->device_id);

        return $chosen->isRoll() ? $chosen : PaperSize::Roll80;
    }
}
