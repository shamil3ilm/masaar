<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocumentEvent;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PeppolDebugDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_document_reports_error(): void
    {
        $this->artisan('peppol:debug-document', ['document' => (string) Str::uuid()])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_document_diagnostics_outputs_details(): void
    {
        $org = Organization::create([
            'name' => 'Gulf Enterprise UAE LLC',
            'country' => 'AE',
            'vat_number' => '100456789000003',
        ]);

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Invoice xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:Invoice-2\"><ID>INV-UAE-001</ID></Invoice>";

        $doc = PeppolDocument::acrossTenants()->create([
            'org_id' => $org->id,
            'direction' => 'out',
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-3.0:ae',
            'profile_id' => 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
            'document_type' => 'Invoice',
            'xml' => $xml,
            'asp_message_id' => 'asp-msg-uuid-999',
            'status' => 'delivered',
            'mls_code' => 'AP',
            'sender_endpoint' => '0184:100456789000003',
            'receiver_endpoint' => '0184:100999888777003',
            'attempts' => 1,
            'reporting_status' => 'cleared',
            'authority_reference' => 'FTA-REF-554433',
        ]);

        PeppolDocumentEvent::create([
            'document_id' => $doc->id,
            'source' => 'callback',
            'track' => 'delivery',
            'status' => 'delivered',
            'reporting_status' => 'cleared',
            'applied' => true,
            'mls_code' => 'AP',
            'asp_message_id' => 'asp-msg-uuid-999',
            'authority_reference' => 'FTA-REF-554433',
            'occurred_at' => now(),
        ]);

        $this->artisan('peppol:debug-document', [
            'document' => (string) $doc->id,
            '--events' => true,
            '--xml' => true,
            '--raw' => true,
        ])
            ->expectsOutputToContain('Peppol / UAE Document Diagnostics')
            ->expectsOutputToContain('Gulf Enterprise UAE LLC')
            ->expectsOutputToContain('0184:100456789000003')
            ->expectsOutputToContain('FTA-REF-554433')
            ->expectsOutputToContain('Event Lifecycle History')
            ->expectsOutputToContain('Formatted PINT AE XML Payload')
            ->assertSuccessful();
    }
}
