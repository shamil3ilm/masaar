<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\TlvEncoder;
use App\Domains\Platform\Services\PublicSite;
use App\Support\QrCodeRenderer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The home page's sample invoice carries a QR code a phone can read, and
 * what it reads is the card's own figures.
 *
 * It used to be a 7x7 drawing of a QR code's shape that no reader could
 * decode. No QR reader is installed here, so the picture is checked the
 * other way round: its modules are read back off the SVG and compared with
 * the code an independent encode of the payload gives, and the payload is
 * decoded back to its tags.
 */
class SampleQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_decodes_to_the_five_tags(): void
    {
        $site = app(PublicSite::class);
        $sample = $site->sampleInvoice();
        $qr = $site->sampleQr();

        $this->assertNotNull($qr);
        $this->assertSame([
            1 => $sample->sellerName,
            2 => '300000000000003',
            3 => '2026-01-15T09:30:00Z',
            4 => $sample->total,
            5 => $sample->tax,
        ], app(TlvEncoder::class)->decode($qr['payload']));
        $this->assertSame('300000000000003', $qr['taxNumber']);

        // The card's lines add up to what the code says.
        $this->assertSame((float) $sample->total, array_sum(array_map('floatval', $sample->lines)) + (float) $sample->tax);
    }

    public function test_the_svg_draws_that_payload(): void
    {
        $qr = app(PublicSite::class)->sampleQr();
        $this->assertNotNull($qr);

        $expected = Encoder::encode($qr['payload'], ErrorCorrectionLevel::L())->getMatrix();
        $modules = $expected->getWidth();
        $quiet = QrCodeRenderer::QUIET_ZONE;
        $span = $modules + 2 * $quiet;

        $this->assertStringContainsString(sprintf('viewBox="0 0 %1$d %1$d"', $span), $qr['svg']);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $qr['svg']);
        $this->assertStringContainsString(sprintf('width="%1$d" height="%1$d"', PublicSite::SAMPLE_QR_SIZE), $qr['svg']);

        preg_match_all('/M(\d+) (\d+)h1v1h-1z/', $qr['svg'], $cells, PREG_SET_ORDER);
        $drawn = [];
        // Offset by the quiet zone: a module drawn inside it would match none
        // of the code's.
        foreach ($cells as [, $x, $y]) {
            $drawn[((int) $y - $quiet).','.((int) $x - $quiet)] = true;
        }

        $wanted = [];
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($expected->get($x, $y) === 1) {
                    $wanted[$y.','.$x] = true;
                }
            }
        }

        ksort($drawn);
        ksort($wanted);
        $this->assertSame($wanted, $drawn);
    }

    public function test_it_is_drawn_once_then_cached(): void
    {
        $renderer = $this->spy(QrCodeRenderer::class);
        $renderer->shouldReceive('gridSvg')->once()->andReturn('<svg></svg>');

        Cache::flush();
        $site = app(PublicSite::class);
        $site->sampleQr();
        $site->sampleQr();

        $renderer->shouldHaveReceived('gridSvg')->once();
    }

    public function test_the_home_page_shows_it_labelled(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-sample-qr', false)
            ->assertSee('shape-rendering="crispEdges"', false)
            ->assertSee('300000000000003')
            ->assertSee('Sample Trading Co.')
            ->assertSeeInOrder(['data-sample-qr', 'Sample</p>'], false);
    }
}
