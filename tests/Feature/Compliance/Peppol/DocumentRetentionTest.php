<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Peppol\Exceptions\DocumentRetainedException;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stored UAE e-invoice is the taxpayer's record and outlives any attempt
 * to delete it, or its organization - as an issued invoice does
 * (InvoiceRetentionTest).
 */
class DocumentRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_cannot_be_deleted(): void
    {
        $document = $this->document(Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE']));

        try {
            PeppolDocument::withoutTenantScope(fn () => $document->delete());
            $this->fail('A stored e-invoice was deleted.');
        } catch (DocumentRetainedException) {
            // Refused, as it should be.
        }

        $this->assertNotNull(PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->find($document->id)));
    }

    public function test_its_organization_stays_too(): void
    {
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE']);
        $document = $this->document($gulf);

        try {
            $gulf->delete();
            $this->fail('An organization with e-invoices was deleted.');
        } catch (QueryException) {
            // Refused by the database, as for invoices.
        }

        $this->assertNotNull(Organization::query()->find($gulf->id));
        $this->assertNotNull(PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->find($document->id)));
    }

    private function document(Organization $organization): PeppolDocument
    {
        return PeppolDocument::withoutTenantScope(fn () => PeppolDocument::create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'status' => 'sent',
            'xml' => '<Invoice/>',
        ]));
    }
}
