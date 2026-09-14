<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * What Submitter writes once ZATCA has answered.
 *
 * The invoice's status, its branch's counter and the audit entry describe one
 * event. Written separately, a failure part-way leaves an accepted invoice
 * with no audit trail, or a branch count that disagrees with its invoices.
 */
class AcceptanceRecordTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate']]
        );

        Http::fake(['*' => Http::response([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => ['status' => 'PASS'],
        ])]);

        $this->branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-1',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
            'onboarding_status' => Branch::STATUS_ACTIVE,
        ]));
    }

    public function test_accepted_invoice_is_counted_and_audited(): void
    {
        $invoice = $this->invoice();

        app(Submitter::class)->submit($invoice, $this->organization);

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertSame(1, (int) $this->branch->fresh()->invoice_count);
        $this->assertTrue(AuditLog::where('action', 'zatca.submission.success')->exists());
    }

    public function test_failed_audit_leaves_invoice_unaccepted(): void
    {
        $invoice = $this->invoice();

        AuditLog::creating(fn (AuditLog $log) => $log->action === 'zatca.submission.success'
            ? throw new \RuntimeException('audit store down')
            : null);

        $this->assertThrows(
            fn () => app(Submitter::class)->submit($invoice, $this->organization),
            \RuntimeException::class
        );

        $this->assertNotSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
        $this->assertSame(0, (int) $this->branch->fresh()->invoice_count);
    }

    private function invoice(): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'invoice_number' => 'INV-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer Co',
            'buyer_vat_number' => '311111111111113',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}
