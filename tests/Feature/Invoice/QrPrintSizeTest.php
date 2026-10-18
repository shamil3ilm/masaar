<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Support\Text\ReceiptCanvas;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * The country's QR printed large enough to scan: about 40 mm on a sheet and
 * an 80 mm roll, and on a 58 mm roll as wide as its 48 mm of print allows
 * with the quiet zone - never under 36 mm.
 *
 * A Phase 2 code carries the signature, the public key and the certificate
 * signature, about a hundred modules across; at the 32 mm it was printed at,
 * each module was 0.3 mm, under two pixels in a 150 dpi scan. The decoding
 * itself is checked on the rendered PDFs and images (print-check).
 */
class QrPrintSizeTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
    }

    public function test_the_pdf_draws_it_at_size(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        foreach (['a4' => 40, 'letter' => 40, 'a5' => 40, '80mm' => 40, '58mm' => 46] as $paper => $mm) {
            $html = app(InvoicePdf::class)->html($invoice, $this->organization, PaperSize::from($paper));

            $this->assertMatchesRegularExpression('#<img src="data:image/png;base64,[^"]+"[^>]*style="width: '.$mm.'mm; height: '.$mm.'mm;"#', $html, $paper);
        }
    }

    /**
     * On the printer's own dots a module is a whole number of them, so the
     * code comes out a few millimetres from what is asked, never under
     * 36 mm and never past the paper's 72 or 48 mm of print.
     */
    public function test_the_receipt_image_fits_the_roll(): void
    {
        $payload = (string) $this->sampleInvoice($this->organization, 'simplified', 2)->qr_code;

        foreach ([PaperSize::Roll80, PaperSize::Roll58] as $paper) {
            $dots = (int) $paper->printerDots();
            $canvas = new ReceiptCanvas($dots, 203);
            $canvas->qr($payload, $paper->qrMm(), atLeastMm: 36.0);
            [$left, $right] = $this->inkAcross($canvas->image(0));
            // The finder patterns put ink in the symbol's first and last
            // columns; the quiet zone is four more modules each side.
            $modules = Encoder::encode($payload, ErrorCorrectionLevel::L())->getMatrix()->getWidth();
            $module = intdiv($right - $left + 1, $modules);
            $withQuietZone = ($modules + 8) * $module / 203 * 25.4;

            $this->assertGreaterThanOrEqual(36.0, $withQuietZone, $paper->value);
            $this->assertLessThanOrEqual($dots, ($modules + 8) * $module, $paper->value.': the quiet zone does not fit');
            $this->assertLessThanOrEqual(48.0, $withQuietZone, $paper->value);
        }
    }

    /**
     * The leftmost and rightmost black columns.
     *
     * @return array{int, int}
     */
    private function inkAcross(\GdImage $image): array
    {
        $left = PHP_INT_MAX;
        $right = -1;

        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                if (imagecolorsforindex($image, imagecolorat($image, $x, $y))['red'] === 0) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    break;
                }
            }
        }

        return [$left, $right];
    }
}
