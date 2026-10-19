<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Services\PublicSite;
use App\Support\Brand;
use App\Support\QrCodeRenderer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The home page's invoice card carries a QR code that opens the website,
 * drawn in the brand's navy with round dots, rounded finder patterns and the
 * product's mark in the middle - and a phone can still read it.
 *
 * Two checks. The modules are read back off the SVG and compared with an
 * independent encode of the URL at level H: every dot drawn is one of the
 * code's, and the only ones left out sit under the mark. And, where a QR
 * reader is configured (QR_DECODER_PYTHON: a Python with zxingcpp and
 * pymupdf), the SVG is rasterized at 96 and 150 dpi and decoded back to the
 * URL with the mark in place; without one that check skips, as the ZATCA
 * SDK's do.
 */
class SiteQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_encodes_the_home_url(): void
    {
        $this->assertSame(url('/'), app(PublicSite::class)->siteQr()['url']);
    }

    public function test_the_dots_draw_that_url(): void
    {
        $qr = app(PublicSite::class)->siteQr();
        $expected = Encoder::encode($qr['url'], ErrorCorrectionLevel::H())->getMatrix();
        $modules = $expected->getWidth();
        $quiet = QrCodeRenderer::QUIET_ZONE;

        $this->assertStringContainsString(sprintf('viewBox="0 0 %1$d %1$d"', $modules + 2 * $quiet), $qr['svg']);

        preg_match_all('/M(\d+)\.05 (\d+)\.5a/', $qr['svg'], $cells, PREG_SET_ORDER);
        $drawn = [];
        foreach ($cells as [, $x, $y]) {
            $drawn[((int) $x - $quiet).','.((int) $y - $quiet)] = true;
        }

        $wanted = [];
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($expected->get($x, $y) === 1 && ! $this->inFinder($x, $y, $modules)) {
                    $wanted[$x.','.$y] = true;
                }
            }
        }

        $this->assertSame([], array_keys(array_diff_key($drawn, $wanted)), 'a dot the code does not have');

        // What is left out is under the mark: a centred square no wider than
        // a fifth of the symbol, plus the half module of paper around it.
        $reach = $modules * QrCodeRenderer::MARK_SHARE / 2 + 1;
        foreach (array_keys(array_diff_key($wanted, $drawn)) as $cell) {
            [$x, $y] = array_map('intval', explode(',', $cell));
            $this->assertLessThanOrEqual($reach, abs($x + 0.5 - $modules / 2), "module {$cell} missing outside the mark");
            $this->assertLessThanOrEqual($reach, abs($y + 0.5 - $modules / 2), "module {$cell} missing outside the mark");
        }
        $this->assertLessThan(0.08, count(array_diff_key($wanted, $drawn)) / ($modules * $modules));
    }

    public function test_the_finders_and_mark_are_drawn(): void
    {
        $qr = app(PublicSite::class)->siteQr();
        $modules = Encoder::encode($qr['url'], ErrorCorrectionLevel::H())->getMatrix()->getWidth();
        $quiet = QrCodeRenderer::QUIET_ZONE;

        foreach ([[0, 0], [$modules - 7, 0], [0, $modules - 7]] as [$x, $y]) {
            $this->assertStringContainsString(sprintf('<rect x="%d" y="%d" width="7" height="7"', $x + $quiet, $y + $quiet), $qr['svg']);
            $this->assertStringContainsString(sprintf('<rect x="%d" y="%d" width="5" height="5"', $x + $quiet + 1, $y + $quiet + 1), $qr['svg']);
            $this->assertStringContainsString(sprintf('<rect x="%d" y="%d" width="3" height="3"', $x + $quiet + 2, $y + $quiet + 2), $qr['svg']);
        }

        $this->assertStringContainsString('data-mark', $qr['svg']);
        $this->assertMatchesRegularExpression('/<rect data-mark="" [^>]*width="([\d.]+)"/', $qr['svg']);
        preg_match('/<rect data-mark="" [^>]*width="([\d.]+)"/', $qr['svg'], $mark);
        $this->assertLessThanOrEqual($modules * 0.2 + 0.001, (float) $mark[1]);

        // Strict CSP: attributes only, nothing the policy would have to allow.
        $this->assertStringNotContainsString('style', $qr['svg']);
        $this->assertStringNotContainsString('<script', $qr['svg']);
    }

    public function test_it_is_drawn_once_per_url(): void
    {
        $renderer = $this->spy(QrCodeRenderer::class);
        $renderer->shouldReceive('markedSvg')->andReturn('<svg></svg>');

        Cache::flush();
        $site = app(PublicSite::class);
        $site->siteQr();
        $site->siteQr();
        $renderer->shouldHaveReceived('markedSvg')->once();

        config(['app.url' => 'https://elsewhere.test']);
        app('url')->forceRootUrl('https://elsewhere.test');
        $site->siteQr();
        $renderer->shouldHaveReceived('markedSvg')->twice();
    }

    public function test_the_home_page_shows_it_uncaptioned(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-site-qr', false)
            ->assertDontSee('Scan to visit '.Brand::name('en'))
            ->assertDontSee('امسح لزيارة '.Brand::name('ar'))
            ->assertSee('Sample Trading Co.')
            ->assertSee('Sample</p>', false)
            // No ZATCA sample: no VAT number, no TLV.
            ->assertDontSee('300000000000003');
    }

    public function test_a_reader_decodes_it_marked(): void
    {
        $python = (string) getenv('QR_DECODER_PYTHON');
        if ($python === '' || ! is_file($python)) {
            $this->markTestSkipped('Set QR_DECODER_PYTHON to a Python with zxingcpp and pymupdf to decode the drawing.');
        }

        $qr = app(PublicSite::class)->siteQr();
        $file = tempnam(sys_get_temp_dir(), 'site-qr').'.svg';
        file_put_contents($file, $qr['svg']);

        $script = <<<'PY'
            import sys, pymupdf, zxingcpp
            from PIL import Image
            page = pymupdf.open(sys.argv[1])[0]
            for dpi in sys.argv[2:]:
                # The SVG's width is in CSS pixels, 96 to the inch.
                zoom = int(dpi) / 96
                pix = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom))
                image = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
                texts = [c.text for c in zxingcpp.read_barcodes(image) if c.format == zxingcpp.BarcodeFormat.QRCode]
                print(dpi + '\t' + '\t'.join(texts))
            PY;

        try {
            $process = new Process([$python, '-P', '-c', $script, $file, '96', '150']);
            $process->mustRun();
        } finally {
            @unlink($file);
        }

        $read = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $fields = explode("\t", trim($line));
            $read[$fields[0]] = array_slice($fields, 1);
        }

        $this->assertSame(['96' => [$qr['url']], '150' => [$qr['url']]], $read);
    }

    private function inFinder(int $x, int $y, int $modules): bool
    {
        $near = fn (int $v): bool => $v < 7;
        $far = fn (int $v): bool => $v >= $modules - 7;

        return ($near($x) && $near($y)) || ($far($x) && $near($y)) || ($near($x) && $far($y));
    }
}
