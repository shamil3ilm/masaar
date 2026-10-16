<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Peppol\PeppolEngine;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The UAE behind the compliance router: submitting prepares the document
 * the transport sends, and its status is the document's.
 */
class PintEngineTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_router_sends_uae_to_peppol(): void
    {
        $this->assertInstanceOf(PeppolEngine::class, app(ComplianceRouter::class)->engineFor($this->profile($this->uaeOrganization())));
    }

    public function test_submit_prepares_a_ready_document(): void
    {
        $organization = $this->uaeOrganization();
        $result = app(ComplianceRouter::class)->submit($this->uaeInvoice($organization), $this->profile($organization));

        $this->assertTrue($result->success);
        $this->assertSame('ready', $result->status);
        $this->assertNotNull(PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->find($result->submissionId)));
    }

    public function test_submit_reports_missing_fields(): void
    {
        $organization = $this->uaeOrganization(['emirate' => null]);
        $result = app(ComplianceRouter::class)->submit($this->uaeInvoice($organization), $this->profile($organization));

        $this->assertFalse($result->success);
        $this->assertContains('Seller country subdivision is missing.', $result->rawResponse['problems']);
    }

    public function test_validate_lists_problems(): void
    {
        $organization = $this->uaeOrganization(['endpoint_id' => null]);
        $router = app(ComplianceRouter::class);

        $result = $router->validate($this->uaeInvoice($organization), $this->profile($organization));

        $this->assertFalse($result->valid);
        $this->assertContains('Seller electronic address is missing.', $result->errors);
        $this->assertTrue($router->validate($this->uaeInvoice($this->uaeOrganization()), $this->profile($organization))->valid);
    }

    public function test_status_reads_the_stored_document(): void
    {
        $organization = $this->uaeOrganization();
        $profile = $this->profile($organization);
        $id = (string) app(ComplianceRouter::class)->submit($this->uaeInvoice($organization), $profile)->submissionId;
        PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->whereKey($id)->update(['status' => 'rejected', 'last_error' => 'Unknown receiver']));

        $result = app(ComplianceRouter::class)->checkStatus($id, $profile);

        $this->assertFalse($result->success);
        $this->assertSame(['rejected', 'Unknown receiver'], [$result->status, $result->errorMessage]);
    }

    public function test_rival_document_is_not_found(): void
    {
        $organization = $this->uaeOrganization();
        $id = (string) app(ComplianceRouter::class)->submit($this->uaeInvoice($organization), $this->profile($organization))->submissionId;

        $result = app(ComplianceRouter::class)->checkStatus($id, $this->profile($this->uaeOrganization()));

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    private function profile(Organization $organization): ComplianceProfile
    {
        return ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::query()->where('org_id', $organization->id)->firstOrFail());
    }
}
