<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Invoice\Enums\PaperSize;
use App\Support\Text\ReceiptCanvas;
use GdImage;

/**
 * A receipt as a picture at a thermal printer's own resolution: one pixel
 * per dot of its head, black or white.
 *
 * For printers driven without the browser - ESC/POS from a till's software
 * (EscPos) or an image a POS system prints as it is. The text is drawn here,
 * Arabic included, so the printer needs no Arabic code page or font of its
 * own: it prints dots.
 *
 * The same receipt, in the same order and the same type sizes, as the roll
 * layout the browser and the PDF use (print/receipt.blade.php), from the
 * same Receipt the country describes.
 */
class ReceiptImage
{
    /** The country's QR code on every paper, in millimetres. */
    private const QR_MM = 32.0;

    /**
     * @param  int  $dots  the printer's dots across: 576 for an 80 mm roll at 203 dpi, 384 for 58 mm
     * @param  array{label: string, payload: string, encoding: string}|null  $extraQr  the organization's own QR (InvoiceExtraQr)
     */
    public function render(Receipt $receipt, PaperSize $paper, int $dots, int $dpi, ?GdImage $logo = null, ?array $extraQr = null): GdImage
    {
        $small = $paper === PaperSize::Roll58;
        $body = $small ? 7.0 : 8.0;
        $label = $small ? 6.5 : 7.0;
        $canvas = new ReceiptCanvas($dots, $dpi);

        $canvas->space(1);

        if ($logo !== null) {
            $canvas->picture($logo);
            $canvas->space(1.5);
        }

        $canvas->text($receipt->title->en, $small ? 9 : 10.5, true, ReceiptCanvas::CENTER);
        $canvas->text($receipt->title->ar, $small ? 9 : 10.5, true, ReceiptCanvas::CENTER);

        foreach ($receipt->sections as $section) {
            $canvas->rule();

            if ($section->heading) {
                $canvas->pair($section->heading->en, $section->heading->ar, $body, true);
            }

            if ($section->name) {
                $canvas->text($section->name, $body, true);
            }

            foreach ($section->rows as $row) {
                $canvas->pair($row->label->en, $row->label->ar, $label);
                $canvas->text($row->value, $body, $row->strong, ReceiptCanvas::LEFT);
            }

            foreach ($section->lines as $line) {
                $canvas->text($line, $body);
            }
        }

        $canvas->rule();
        $canvas->pair($receipt->quantityLabel->en.' × '.$receipt->unitPriceLabel->en, $receipt->totalLabel->en, $label);
        $canvas->pair($receipt->quantityLabel->ar.' × '.$receipt->unitPriceLabel->ar, $receipt->totalLabel->ar, $label);

        foreach ($receipt->items as $item) {
            $canvas->space(1.2);
            $canvas->text($item->description, $body, true);

            $canvas->pair($item->quantity.' × '.$item->unitPrice, '= '.$item->total, $body, startRtl: false);

            if ($item->discount) {
                $canvas->pair('Discount  الخصم', $item->discount, $label);
            }

            foreach ($item->details as $detail) {
                $this->detail($canvas, $detail, $label);
            }

            foreach ($item->notes as $note) {
                $canvas->text($note, $label);
            }
        }

        if ($receipt->itemsNote) {
            $canvas->text($receipt->itemsNote->en, $label, false, ReceiptCanvas::RIGHT);
            $canvas->text($receipt->itemsNote->ar, $label, false, ReceiptCanvas::RIGHT);
        }

        $canvas->rule();

        foreach ($receipt->totals as $row) {
            $points = $row->strong ? ($small ? 8.5 : 10.0) : $body;
            $canvas->pair($row->label->en, $row->value, $points, $row->strong);
            $canvas->text($row->label->ar, $label, $row->strong, ReceiptCanvas::LEFT);
        }

        if ($receipt->notes) {
            $canvas->rule();
            $canvas->pair($receipt->notes->label->en, $receipt->notes->label->ar, $label);
            $canvas->text($receipt->notes->value, $body);
        }

        if ($receipt->qrPayload !== '') {
            $canvas->rule();
            $canvas->qr($receipt->qrPayload, self::QR_MM);
        }

        // The organization's own QR, apart and labelled: never in the
        // country's.
        if ($extraQr !== null && $extraQr['payload'] !== '') {
            $canvas->rule();
            $canvas->text($extraQr['label'], $label, true, ReceiptCanvas::CENTER);
            $canvas->qr($extraQr['payload'], InvoiceExtraQr::SIZE_MM, $extraQr['encoding']);
        }

        foreach ($receipt->footer as $line) {
            $canvas->space(1);
            $canvas->text($line, 6.0, false, ReceiptCanvas::CENTER);
        }

        return $canvas->image();
    }

    /** The image as a PNG. */
    public function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * A standard invoice's per-line amounts: the caption in both languages
     * and the amount, on one line when they fit.
     */
    private function detail(ReceiptCanvas $canvas, ReceiptRow $detail, float $points): void
    {
        $canvas->pair($detail->label->en.'  '.$detail->label->ar, $detail->value, $points);
    }
}
