<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A receipt for a printer driven without the browser: an image at the
 * printer's dots, and ESC/POS for POS software and ERPs.
 */
class ReceiptPrintingTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    private User $cashier;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->cashier = User::factory()->create();
        $this->cashier->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
        $this->token = JWTAuth::claims(['org_id' => $this->organization->id, 'role' => 'member'])->fromUser($this->cashier);
    }

    /**
     * One pixel per dot - 576 across an 80 mm roll, 384 across 58 mm at
     * 203 dpi - and every pixel black or white.
     */
    public function test_the_image_is_the_printers_dots(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 6);

        foreach (['80mm' => 576, '58mm' => 384] as $paper => $dots) {
            $png = $this->withToken($this->token)->get('/api/invoices/'.$invoice->id.'/receipt-image?paper='.$paper)
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->getContent();
            $image = imagecreatefromstring((string) $png);

            $this->assertSame($dots, imagesx($image));
            $this->assertGreaterThan(1500, imagesy($image));
            $this->assertSame([[0, 0, 0], [255, 255, 255]], $this->colours($image));
        }
    }

    /**
     * The till's own printer: its dots and resolution, unless the caller
     * names them.
     */
    public function test_the_tills_printer_sets_the_dots(): void
    {
        $till = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id, 'name' => 'Till 1', 'device_serial' => 'EGS-T1', 'street' => 'King Fahd Road',
            'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]));
        app(PrintPreferences::class)->setReceiptPrinter((string) $this->organization->id, $till->id, 300, 832);
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2, $till->id);

        $tills = imagecreatefromstring((string) $this->withToken($this->token)->get('/api/invoices/'.$invoice->id.'/receipt-image')->assertOk()->getContent());
        $named = imagecreatefromstring((string) $this->withToken($this->token)->get('/api/invoices/'.$invoice->id.'/receipt-image?dots=512&dpi=180')->assertOk()->getContent());

        $this->assertSame(832, imagesx($tills));
        $this->assertSame(512, imagesx($named));
        $this->withToken($this->token)->getJson('/api/invoices/'.$invoice->id.'/receipt-image?dots=500')->assertStatus(422);
    }

    /**
     * ESC/POS: initialise, the receipt as raster bands 72 bytes wide on an
     * 80 mm roll, the cut, and the drawer when asked for.
     */
    public function test_escpos_prints_cuts_and_opens(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 3);

        $bytes = (string) $this->withToken($this->token)->get('/api/invoices/'.$invoice->id.'/escpos?drawer=2')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->getContent();

        $this->assertStringStartsWith("\x1B\x40\x1D\x76\x30\x00\x48\x00", $bytes);
        $this->assertStringContainsString("\x1B\x64\x04\x1D\x56\x42\x00", $bytes);
        $this->assertStringEndsWith("\x1B\x70\x00\x19\xFA", $bytes);

        $uncut = (string) $this->withToken($this->token)->get('/api/invoices/'.$invoice->id.'/escpos?paper=58mm&cut=0')->getContent();

        $this->assertStringStartsWith("\x1B\x40\x1D\x76\x30\x00\x30\x00", $uncut);
        $this->assertStringNotContainsString("\x1D\x56\x42\x00", $uncut);
    }

    /** Not before the invoice may be handed to the buyer. */
    public function test_a_draft_is_refused(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 1);
        $invoice->forceFill(['cleared_xml' => null])->saveQuietly();

        $this->withToken($this->token)->getJson('/api/invoices/'.$invoice->id.'/escpos')->assertStatus(409);
        $this->withToken($this->token)->getJson('/api/invoices/'.$invoice->id.'/receipt-image?paper=a4')->assertStatus(422);
    }

    public function test_the_portal_downloads_the_image(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 2);

        $response = $this->actingAs($this->cashier)->withSession(['portal_org_id' => $this->organization->id])
            ->get(route('portal.invoices.receipt-image', ['invoiceId' => $invoice->id, 'paper' => '58mm']))
            ->assertOk();

        $this->assertSame(384, imagesx(imagecreatefromstring((string) $response->getContent())));
        $this->assertStringContainsString('-receipt.png', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * @return list<list<int>>
     */
    private function colours(\GdImage $image): array
    {
        $found = [];

        for ($y = 0; $y < imagesy($image); $y += 3) {
            for ($x = 0; $x < imagesx($image); $x += 3) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $found[$rgb['red'].','.$rgb['green'].','.$rgb['blue']] = [$rgb['red'], $rgb['green'], $rgb['blue']];
            }
        }

        ksort($found);

        return array_values($found);
    }
}
