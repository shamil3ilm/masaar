<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\DocumentArchive;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * A period's UAE e-invoices as one ZIP for the taxpayer's own records: each
 * PINT AE document exactly as it was sent, its PDF, a manifest with what the
 * service provider and the authority answered, and a SHA-256 of every file.
 */
class UaeDocumentArchiveTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gulf = $this->uaeOrganization();
    }

    public function test_files_are_named_by_trn(): void
    {
        $invoice = $this->sent('AE/2026-001');

        $this->assertSame('100123456700003_20261010_AE-2026-001', app(DocumentArchive::class)->fileName($invoice, $this->gulf));
    }

    public function test_archive_holds_xml_as_sent(): void
    {
        $first = $this->sent('AE-1');
        $this->sent('AE-2');
        $document = PeppolDocument::acrossTenants()->where('invoice_id', $first->id)->sole();
        $document->forceFill(['status' => 'delivered', 'reporting_status' => 'reported', 'authority_reference' => 'FTA-1'])->save();
        $path = (string) tempnam(sys_get_temp_dir(), 'test');

        $count = app(DocumentArchive::class)->write($this->gulf, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $path, withPdf: false);

        $this->assertSame(2, $count);
        $zip = new ZipArchive;
        $zip->open($path);
        $name = app(DocumentArchive::class)->fileName($first, $this->gulf);
        $xml = (string) $zip->getFromName("xml/{$name}.xml");

        $this->assertSame((string) $document->xml, $xml);
        $this->assertStringContainsString(hash('sha256', $xml)."  xml/{$name}.xml", (string) $zip->getFromName('SHA256SUMS'));

        $manifest = array_map('str_getcsv', array_filter(explode("\n", (string) $zip->getFromName('manifest.csv'))));
        $row = array_combine($manifest[0], $manifest[1]);
        $this->assertSame([$name, 'AE-1', $document->document_uuid, 'delivered', 'reported', 'FTA-1', hash('sha256', $xml)],
            [$row['file'], $row['invoice_number'], $row['document_uuid'], $row['delivery_status'], $row['reporting_status'], $row['authority_reference'], $row['xml_sha256']]);
        $zip->close();
        unlink($path);
    }

    public function test_outside_the_period_is_left_out(): void
    {
        $this->sent('AE-1');

        $path = (string) tempnam(sys_get_temp_dir(), 'test');
        $count = app(DocumentArchive::class)->write($this->gulf, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $path, withPdf: false);

        $this->assertSame(0, $count);
        unlink($path);
    }

    public function test_command_writes_the_archive(): void
    {
        $this->sent('AE-1');
        $path = sys_get_temp_dir().'/uae-archive-'.uniqid().'.zip';

        $this->artisan('uae:export-archive', [
            'organization' => $this->gulf->id, '--from' => '2026-10-10', '--to' => '2026-10-10', '--path' => $path, '--without-pdf' => true,
        ])->expectsOutputToContain('1 documents written')->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertSame(1, AuditLog::query()->where('action', 'uae.archive.exported')->count());
        unlink($path);
    }

    public function test_portal_download_is_for_accountants_up(): void
    {
        $this->sent('AE-1');
        $range = ['from' => '2026-10-10', 'to' => '2026-10-10'];

        $this->actingAs($this->member('accountant'))->get(route('portal.uae.archive', $range))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->actingAs($this->member('member'))->get(route('portal.uae.archive', $range))->assertForbidden();
    }

    public function test_portal_period_is_capped(): void
    {
        $this->actingAs($this->member('owner'))
            ->get(route('portal.uae.archive', ['from' => '2026-01-01', 'to' => '2026-03-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_console_download_is_on_record(): void
    {
        $this->sent('AE-1');
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);

        $this->actingAs($admin)->get(route('admin.organizations.uae-archive', [$this->gulf->id, 'from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame(1, AuditLog::query()->where('action', 'uae.archive.exported')->count());
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->gulf->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    /** An issued invoice with its PINT AE document stored, as issuing leaves it. */
    private function sent(string $number): Invoice
    {
        $invoice = $this->uaeInvoice($this->gulf, ['invoice_number' => $number]);
        app(PeppolPreparer::class)->prepare($invoice);

        return $invoice;
    }
}
