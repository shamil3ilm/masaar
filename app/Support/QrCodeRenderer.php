<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR payload, as something a phone can read.
 *
 * It draws whatever string it is given: an invoice's stored QR payload (for
 * Saudi Arabia, the base64 TLV string DocumentBuilder writes to
 * invoices.qr_code), a two-factor otpauth URI, or the website's address for
 * the home page. The payload is already correct; this only draws it.
 *
 * No constructor arguments: there is nothing here worth injecting.
 */
class QrCodeRenderer
{
    /** Big enough to scan from a printed receipt at arm's length. */
    private const SIZE = 180;

    /** 32 mm on the page at about 475 dpi: sharp on any printer. */
    private const PRINT_SIZE = 600;

    /** The light margin a reader needs around the code, in modules (ISO/IEC 18004, 9.1). */
    public const QUIET_ZONE = 4;

    /** The marked code's centre mark, as a share of the symbol's width. */
    public const MARK_SHARE = 0.2;

    /*
     * The marked code's colours, from resources/css/app.css: the logo's
     * navy (ink-900) on the invoice card's paper, and the logo's teal path
     * (teal-500) and sand node (sand-200).
     */
    private const INK = '#1A1F36';

    private const PAPER = '#FAF8F4';

    private const ROUTE = '#14B8A6';

    private const NODE = '#EADBC8';

    /**
     * @param  string  $encoding  how the payload's bytes are read: Latin-1, as
     *                            the country's base64 payload is, or UTF-8 for
     *                            text in Arabic, which the code then declares
     */
    public function svg(string $payload, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): string
    {
        if (trim($payload) === '') {
            return '';
        }

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(self::SIZE),
                new SvgImageBackEnd,
            )
        );

        return $writer->writeString($payload, $encoding);
    }

    /**
     * The code with the Masaar mark in its middle, softened for the home
     * page: round dots, rounded finder patterns, the logo's navy on the
     * invoice card's paper, one SVG unit per module and the quiet zone
     * around it.
     *
     * Error correction is level H, which restores about 30% of the
     * codewords; the mark covers a fifth of the symbol's width (MARK_SHARE),
     * about 6% of its modules. The finder patterns keep their 7x7 rings and
     * centres, only their corners rounded, so a reader still finds them.
     *
     * The colours are attributes, not classes: fixed whatever the theme or
     * mode, so the dark modules stay on a light tile in dark mode and under
     * an organization's colours, and the content policy has no style to
     * allow. $size is roughly its width in CSS pixels, rounded so each
     * module is a whole number of them.
     */
    public function markedSvg(string $payload, int $size): string
    {
        if (trim($payload) === '') {
            return '';
        }

        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::H())->getMatrix();
        $modules = $matrix->getWidth();
        $span = $modules + 2 * self::QUIET_ZONE;
        $pixels = $span * max(2, (int) round($size / $span));

        $mark = $modules * self::MARK_SHARE;
        // Modules whose cell reaches into the mark or the half module of
        // paper around it are left out.
        $clearFrom = ($modules - $mark) / 2 - 0.5;
        $clearTo = ($modules + $mark) / 2 + 0.5;
        $cleared = fn (int $v): bool => $v + 1 > $clearFrom && $v < $clearTo;

        $dots = '';
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) !== 1 || $this->inFinder($x, $y, $modules) || ($cleared($x) && $cleared($y))) {
                    continue;
                }

                $dots .= sprintf('M%d.05 %d.5a.45 .45 0 1 0 .9 0a.45 .45 0 1 0-.9 0z', $x + self::QUIET_ZONE, $y + self::QUIET_ZONE);
            }
        }

        $finders = '';
        foreach ([[0, 0], [$modules - 7, 0], [0, $modules - 7]] as [$x, $y]) {
            $finders .= $this->finder($x + self::QUIET_ZONE, $y + self::QUIET_ZONE);
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" aria-hidden="true" focusable="false">'
            .'<rect width="%2$d" height="%2$d" rx="2" fill="%3$s"/><path fill="%4$s" d="%5$s"/>%6$s%7$s</svg>',
            $pixels,
            $span,
            self::PAPER,
            self::INK,
            $dots,
            $finders,
            $this->mark(self::QUIET_ZONE + ($modules - $mark) / 2, $mark),
        );
    }

    /** A finder pattern at its top-left module: the 7x7 ring, its light ring and its 3x3 centre, corners rounded. */
    private function finder(int $x, int $y): string
    {
        return sprintf('<rect x="%d" y="%d" width="7" height="7" rx="2" fill="%s"/>', $x, $y, self::INK)
            .sprintf('<rect x="%d" y="%d" width="5" height="5" rx="1.3" fill="%s"/>', $x + 1, $y + 1, self::PAPER)
            .sprintf('<rect x="%d" y="%d" width="3" height="3" rx="0.9" fill="%s"/>', $x + 2, $y + 2, self::INK);
    }

    private function inFinder(int $x, int $y, int $modules): bool
    {
        return ($y < 7 && ($x < 7 || $x >= $modules - 7)) || ($x < 7 && $y >= $modules - 7);
    }

    /**
     * The Masaar mark on its tile, $side modules across from ($at, $at): the
     * geometry of components/logo.blade.php, whose tile spans 10 to 110 of
     * its 120 units, with the path and node drawn heavier so they still read
     * at a dozen pixels.
     */
    private function mark(float $at, float $side): string
    {
        $scale = $side / 100;

        return sprintf('<rect data-mark="" x="%1$s" y="%1$s" width="%2$s" height="%2$s" rx="%3$s" fill="%4$s"/>', $this->number($at), $this->number($side), $this->number($side * 0.2), self::INK)
            .sprintf('<g transform="translate(%1$s %1$s) scale(%2$s)">', $this->number($at - 10 * $scale), $this->number($scale))
            .sprintf('<path d="M30 75 C40 40, 60 40, 70 75 S100 100, 95 55" stroke="%s" stroke-width="9" stroke-linecap="round" fill="none"/>', self::ROUTE)
            .sprintf('<circle cx="95" cy="55" r="9" fill="%s"/></g>', self::NODE);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    /**
     * The same code as a PNG, for the invoice PDF.
     *
     * mPDF turns an SVG into a form XObject that uses graphics states it does
     * not declare in that form's own resources, and the PDF fails PDF/A
     * (ISO 19005-3, 6.2.2). A raster image carries no such references.
     */
    public function png(string $payload, string $encoding = Encoder::DEFAULT_BYTE_MODE_ENCODING): string
    {
        if (trim($payload) === '') {
            return '';
        }

        return (new Writer(new GDLibRenderer(self::PRINT_SIZE)))->writeString($payload, $encoding);
    }
}
