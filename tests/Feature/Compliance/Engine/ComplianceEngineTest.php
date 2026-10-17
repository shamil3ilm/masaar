<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Compliance\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\Services\UaeInvoiceIssuer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Tests\Feature\Compliance\Peppol\PintInvoices;
use Tests\TestCase;

/**
 * The one entry point routes each organization to its own country, and
 * every log line written on the way names that country.
 */
class ComplianceEngineTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
    }

    public function test_saudi_organization_reaches_saudi_issuing(): void
    {
        $this->mock(UaeInvoiceIssuer::class)->shouldNotReceive('issue');
        $this->mock(InvoiceIssuer::class)->shouldReceive('issue')->once()
            ->withArgs(fn (Invoice $invoice, Organization $organization, bool $file, ?string $key): bool => $invoice->id === 'inv-1' && $file && $key === 'erp-1')
            ->andReturn(IssueOutcome::issuedOnly());

        $outcome = app(ComplianceEngine::class)->issue($this->invoice(), $this->saudi(), new IssueOptions(file: true, idempotencyKey: 'erp-1'));

        $this->assertTrue($outcome->issued);
        $this->assertFalse($outcome->attemptedFiling);
    }

    public function test_uae_organization_reaches_uae_issuing(): void
    {
        $this->mock(InvoiceIssuer::class)->shouldNotReceive('issue');
        $organization = $this->uaeOrganization();
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null]);
        Invoice::query()->whereKey($invoice->id)->update([
            'status' => 'draft', 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00',
        ]);

        $outcome = app(ComplianceEngine::class)->issue(Invoice::query()->findOrFail($invoice->id), $organization, new IssueOptions(file: false));

        $document = PeppolDocument::query()->sole();
        $this->assertTrue($outcome->issued);
        $this->assertTrue($outcome->attemptedFiling);
        $this->assertSame([], $outcome->errors);
        $this->assertSame(['submission_id' => $document->id, 'state' => 'ready'], $outcome->submission);
        Queue::assertNotPushed(SendPeppolDocument::class);
    }

    public function test_switched_off_country_is_refused(): void
    {
        config(['peppol.countries.AE.enabled' => false]);
        $this->mock(UaeInvoiceIssuer::class)->shouldNotReceive('issue');

        $this->expectException(JurisdictionUnavailableException::class);

        app(ComplianceEngine::class)->issue($this->invoice(), (new Organization)->forceFill(['id' => 'org-1', 'country' => 'AE']), new IssueOptions(file: true));
    }

    /** The pipeline asks before drafting, so a refused call leaves nothing behind. */
    public function test_pipeline_refuses_before_drafting(): void
    {
        $organization = $this->uaeOrganization();
        config(['peppol.countries.AE.enabled' => false]);

        try {
            app(PipelineService::class)->submitInvoice(['auto_submit' => true], $organization->id);
            $this->fail('A switched-off country was issued.');
        } catch (JurisdictionUnavailableException $e) {
            $this->assertSame('AE', $e->country);
        }

        $this->assertSame(0, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_log_context_names_the_jurisdiction(): void
    {
        $handler = new TestHandler;
        Log::driver()->getLogger()->pushHandler($handler);
        $seen = [];

        $this->mock(InvoiceIssuer::class)->shouldReceive('issue')->once()->andReturnUsing(function () use (&$seen): IssueOutcome {
            $seen = Context::all();
            Log::warning('filing probe');

            return IssueOutcome::issuedOnly();
        });

        app(ComplianceEngine::class)->issue($this->invoice(), $this->saudi(), new IssueOptions(file: false));

        $expected = ['jurisdiction' => 'SA', 'step' => 'issue', 'org_id' => 'org-1', 'invoice_id' => 'inv-1'];
        $this->assertSame($expected, array_intersect_key($seen, $expected));
        $this->assertSame($expected, array_intersect_key($handler->getRecords()[0]->extra, $expected));
        $this->assertFalse(Context::has('jurisdiction'), 'the context outlived the call');
    }

    private function saudi(): Organization
    {
        return (new Organization)->forceFill(['id' => 'org-1', 'country' => 'SA']);
    }

    private function invoice(): Invoice
    {
        return (new Invoice)->forceFill(['id' => 'inv-1', 'org_id' => 'org-1']);
    }
}
