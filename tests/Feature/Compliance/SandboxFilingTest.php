<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * fatoora:sandbox-filing, with the authority faked.
 *
 * The command exists to put the platform's own filing path in front of
 * ZATCA's sandbox, which only the nightly CI job can do. This checks the part
 * that does not need the authority: that it refuses anywhere but the sandbox,
 * and that it really does go through the platform - the device stored and
 * found, every invoice issued, filed with that device's credentials, and the
 * verdict and stamped copy recorded.
 */
class SandboxFilingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->storage = sys_get_temp_dir().'/masaar-sandbox-'.uniqid();
        mkdir($this->storage.'/app/zatca', 0700, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage.'/app/zatca/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->storage.'/app/zatca');
        @rmdir($this->storage.'/app');
        @rmdir($this->storage);

        parent::tearDown();
    }

    public function test_it_refuses_outside_the_sandbox(): void
    {
        Http::fake();
        config(['fatoora.environment' => 'production']);

        $this->artisan('fatoora:sandbox-filing')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_files_through_the_platform(): void
    {
        config(['fatoora.environment' => 'sandbox']);

        $keys = $this->selfSignedCredentials();
        file_put_contents($this->storage.'/app/zatca/pcsid_token.txt', $keys['certificate']);
        file_put_contents($this->storage.'/app/zatca/pcsid_secret.txt', 'sandbox-secret');
        file_put_contents($this->storage.'/app/zatca/ccsid_private_key.pem', $keys['privateKey']);

        Http::fake([
            '*/invoices/clearance/*' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'validationResults' => ['status' => 'PASS'],
                'clearedInvoice' => base64_encode('<Invoice>STAMPED</Invoice>'),
            ]),
            '*/invoices/reporting/*' => Http::response([
                'reportingStatus' => 'REPORTED',
                'validationResults' => ['status' => 'PASS'],
            ]),
        ]);

        $this->artisan('fatoora:sandbox-filing')
            ->expectsOutputToContain('All 4 invoices were accepted')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')
            === ['Basic '.base64_encode(trim($keys['certificate']).':sandbox-secret')]);

        $invoices = Invoice::withoutTenantScope(fn () => Invoice::all());

        $this->assertCount(4, $invoices);

        // The inclusive pair went through the drafter's pricing: ZATCA's
        // worked example, 1,255.00 gross.
        $inclusive = $invoices->where('prices_include_vat', true);
        $this->assertCount(2, $inclusive);
        $this->assertTrue($inclusive->every(fn (Invoice $invoice): bool => (string) $invoice->total === '1255.00' && (string) $invoice->tax_amount === '163.70'));
        $this->assertTrue($invoices->every(fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Accepted));
        $this->assertSame('<Invoice>STAMPED</Invoice>', $invoices->firstWhere('type.value', 'standard')?->cleared_xml);
    }
}
