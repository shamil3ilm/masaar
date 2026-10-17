<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineFallback;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the ERP is promised, pinned before the sequence underneath it moves.
 *
 * submitInvoice() returns a flat array that PipelineController keys its HTTP
 * status off: 207 when errors is non-empty, 201 otherwise. Nothing asserted
 * its shape - BranchRoutingTest reads invoice_id and stops, and the response
 * is absent from docs/openapi.yaml, so the drift tests cannot see it either.
 *
 * The extraction that follows is meant to be a pure move. This is how we find
 * out whether it was.
 */
class PipelineContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        // Connectivity performs a real outbound HEAD request
        // (Connectivity.php:132-136) reached through shouldUseOfflineMode().
        // Left alone, this suite would talk to the internet.
        //
        // shouldUseOfflineMode() is the only method the pipeline reaches on
        // this collaborator (OfflineFallback.php:50-52), and false is what
        // keeps the online branch - the one this file characterises - taken.
        $this->mock(Connectivity::class, function ($mock) {
            $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false);
        });
    }

    /**
     * The landmine. Losing this gate makes every ERP that asked NOT to file
     * start filing with ZATCA - irreversible, on live taxpayer data.
     *
     * Asserted on the collaborator rather than on its leftovers. Counting rows
     * in invoice_submissions and offline_queue cannot fail here: SubmissionGuard
     * throws on the missing certificate (SubmissionTracker.php:68) before
     * createSubmission() writes anything at :71, and queueForOffline() is
     * unreachable while shouldUseOfflineMode() is pinned false. Both counts
     * would read zero with the gate gone, and an absent row cannot tell
     * "never attempted" from "attempted and refused".
     *
     * shouldNotReceive() can, and no environment fact fools it. Give this
     * organization a certificate later and this test still means what its
     * name says.
     */
    public function test_auto_submit_false_does_not_file(): void
    {
        $this->mock(OfflineFallback::class, function ($mock) {
            $mock->shouldNotReceive('submit');
        });

        $result = app(PipelineService::class)->submitInvoice(
            $this->invoiceData(['auto_submit' => false]),
            $this->organization->id,
        );

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['zatca_response']);
        $this->assertSame('issued', $result['status']);
    }

    public function test_the_result_keys_are_stable(): void
    {
        $result = app(PipelineService::class)->submitInvoice(
            $this->invoiceData(['auto_submit' => false]),
            $this->organization->id,
        );

        $this->assertSame([
            'invoice_id', 'uuid', 'invoice_number', 'erp_reference_id', 'status',
            'compliance_status', 'hash', 'qr_code', 'signed_xml', 'cleared_xml',
            'zatca_response', 'totals', 'errors', 'warnings',
        ], array_keys($result));

        $this->assertSame(
            ['subtotal', 'discount_amount', 'tax_amount', 'total'],
            array_keys($result['totals'])
        );
    }

    public function test_a_failed_filing_leaves_it_issued(): void
    {
        $result = app(PipelineService::class)->submitInvoice(
            $this->invoiceData(),
            $this->organization->id,
        );

        $this->assertSame('issued', $result['status']);

        // The prefix is the part PipelineService owns; the suffix belongs to
        // whichever guard threw. Here the organization has no PCSID, so the
        // observed message is 'ZATCA submission failed: ZATCA certificate not
        // found' - asserted on the prefix alone so this stays a statement
        // about the pipeline rather than about SubmissionGuard's wording.
        $this->assertStringStartsWith('ZATCA submission failed: ', $result['errors'][0]);

        // Filing failed, so there is no authority response to report, and the
        // invoice keeps the hash and QR the next invoice chains onto.
        $this->assertNull($result['zatca_response']);
        $this->assertNotNull($result['hash']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoiceData(array $overrides = []): array
    {
        return array_merge([
            // Fixed, not uniqid(): RefreshDatabase hands each test a clean
            // database, so uniqueness buys nothing and only puts a varying
            // value into a test whose job is to record exact ones.
            'invoice_number' => 'PIPE-0001',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'lines' => [[
                'description' => 'Widget',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_rate' => 15,
                'unit_code' => 'PCE',
            ]],
        ], $overrides);
    }
}
