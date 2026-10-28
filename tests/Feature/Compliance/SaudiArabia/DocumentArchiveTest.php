<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DocumentArchive;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use ZipArchive;

/**
 * A period's issued documents as one ZIP: named as ZATCA's XML
 * Implementation Standard (v1.2, section 14) names them, with a manifest
 * and a SHA-256 of every file.
 */
class DocumentArchiveTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'name_ar' => 'أكمي للتجارة',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'secret',
        ]);
    }

    public function test_files_follow_zatcas_naming_convention(): void
    {
        $invoice = $this->issue('INV/2026-001');

        $this->assertMatchesRegularExpression('/^300000000000003_\d{8}T\d{6}_INV-2026-001$/', DocumentArchive::fileName($invoice, $this->acme));
    }

    public function test_archive_holds_documents_and_checksums(): void
    {
        $first = $this->issue('INV-1');
        $this->issue('INV-2');
        $path = tempnam(sys_get_temp_dir(), 'test');

        $count = app(DocumentArchive::class)->write($this->acme, CarbonImmutable::today()->subDay(), CarbonImmutable::today()->addDay(), $path, withPdf: false);

        $this->assertSame(2, $count);
        $zip = new ZipArchive;
        $zip->open($path);
        $name = DocumentArchive::fileName($first, $this->acme);
        $signed = (string) $zip->getFromName("signed/{$name}.xml");

        $this->assertSame((string) $first->signed_xml, $signed);
        $this->assertStringContainsString(hash('sha256', $signed)."  signed/{$name}.xml", (string) $zip->getFromName('SHA256SUMS'));

        $manifest = array_map('str_getcsv', array_filter(explode("\n", (string) $zip->getFromName('manifest.csv'))));
        $this->assertSame('pih', $manifest[0][9]);
        $this->assertSame([$name, 'INV-1', $first->id, '1'], array_slice($manifest[1], 0, 4));
        $this->assertSame($first->hash, $manifest[1][8]);
        $zip->close();
        unlink($path);
    }

    public function test_command_writes_the_archive(): void
    {
        $this->issue('INV-1');
        $path = sys_get_temp_dir().'/archive-'.uniqid().'.zip';

        $this->artisan('fatoora:export-archive', [
            'organization' => $this->acme->id, '--from' => now()->toDateString(), '--to' => now()->toDateString(), '--path' => $path, '--without-pdf' => true,
        ])->expectsOutputToContain('1 documents written')->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertSame(1, AuditLog::query()->where('action', 'zatca.archive.exported')->count());
        unlink($path);
    }

    public function test_portal_download_is_for_accountants_up(): void
    {
        $this->issue('INV-1');
        $range = ['from' => now()->toDateString(), 'to' => now()->toDateString()];

        $this->actingAs($this->member('accountant'))->get(route('portal.zatca.archive', $range))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->actingAs($this->member('member'))->get(route('portal.zatca.archive', $range))->assertForbidden();
    }

    public function test_portal_period_is_capped(): void
    {
        $this->actingAs($this->member('owner'))
            ->get(route('portal.zatca.archive', ['from' => '2026-01-01', 'to' => '2026-03-01']))
            ->assertSessionHasErrors('to');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function issue(string $number): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return $invoice->fresh();
    }
}
