<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Clearing and reporting with the production certificate.
 *
 * Onboarding ended at a production CSID. Whether the authority then accepts a
 * signed invoice, what it sends back, and whether a stamped copy arrives was
 * established only against the SDK offline or against the compliance
 * endpoint - a different endpoint with different rules. The PCSID was
 * obtained, written to disk, and never read back by anything.
 *
 * That gap was described as needing a real taxpayer. It does not: the
 * developer portal issues a PCSID and serves clearance and reporting too.
 *
 * The exchange is faked here, because what a test can establish is the part
 * that is ours - which endpoint each document goes to, that the production
 * certificate signs them, and that a refusal is reported as a refusal. That
 * the authority accepts them is what the scheduled sandbox job establishes,
 * against the authority.
 */
class ProductionSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private string $storage;

    private string $directory;

    /**
     * A storage path of its own.
     *
     * The command reads its credentials from storage_path('app/zatca'), which
     * on a developer's machine holds the real ones from whatever onboarding
     * they last ran. An earlier version of this test wrote its fixtures there
     * and deleted them afterwards, which destroyed four files it had not
     * created. Pointing the whole storage path at a temporary directory means
     * the test cannot reach them at all, rather than being careful about it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/masaar-submission-'.bin2hex(random_bytes(6));

        foreach (['app/zatca', 'framework/views', 'framework/cache', 'logs'] as $path) {
            File::ensureDirectoryExists($this->storage.'/'.$path);
        }

        $this->app->useStoragePath($this->storage);
        $this->directory = $this->storage.'/app/zatca';

        $credentials = $this->selfSignedCredentials();

        File::put($this->directory.'/pcsid_token.txt', 'cHJvZHVjdGlvbi10b2tlbg==');
        File::put($this->directory.'/pcsid_secret.txt', 'production-secret');
        File::put($this->directory.'/pcsid_certificate.pem', $credentials['certificate']);
        File::put($this->directory.'/ccsid_private_key.pem', $credentials['privateKey']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /**
     * Three standard documents are cleared and three simplified are reported,
     * which is what the two routes are for. Sending a simplified document for
     * clearance, or a standard one for reporting, is refused by the authority
     * and the mistake is invisible here without this.
     */
    public function test_each_document_takes_its_own_route(): void
    {
        Http::fake([
            '*/invoices/clearance/single' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'clearedInvoice' => base64_encode('<Invoice/>'),
            ]),
            '*/invoices/reporting/single' => Http::response(['reportingStatus' => 'REPORTED']),
        ]);

        $this->artisan('fatoora:onboard', ['--step' => 'submit', '--target' => 'sandbox'])
            ->assertSuccessful();

        $cleared = 0;
        $reported = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/invoices/clearance/single')) {
                $cleared++;
            }

            if (str_contains($request->url(), '/invoices/reporting/single')) {
                $reported++;
            }
        }

        $this->assertSame(3, $cleared, 'The three standard documents were not sent for clearance.');
        $this->assertSame(3, $reported, 'The three simplified documents were not sent for reporting.');
    }

    /**
     * Clearance asks the authority to stamp the document; reporting only
     * tells it the document exists. The header is what distinguishes them,
     * and without it a standard invoice is accepted without being cleared -
     * which means it was never legally issued.
     */
    public function test_only_clearance_asks_for_a_stamp(): void
    {
        Http::fake([
            '*/invoices/clearance/single' => Http::response(['clearanceStatus' => 'CLEARED']),
            '*/invoices/reporting/single' => Http::response(['reportingStatus' => 'REPORTED']),
        ]);

        $this->artisan('fatoora:onboard', ['--step' => 'submit', '--target' => 'sandbox'])
            ->assertSuccessful();

        foreach (Http::recorded() as [$request]) {
            $asksForAStamp = $request->hasHeader('Clearance-Status', '1');

            $this->assertSame(
                str_contains($request->url(), '/invoices/clearance/single'),
                $asksForAStamp,
                'Clearance-Status is on the wrong request: '.$request->url()
            );
        }
    }

    /**
     * A refusal has to end the command non-zero, or a scheduled run reports
     * success having had every document rejected.
     */
    public function test_a_refusal_fails_the_command(): void
    {
        Http::fake([
            '*' => Http::response([
                'errorMessages' => [['code' => 'BR-KSA-13', 'message' => 'PIH is inValid']],
            ], 400),
        ]);

        $this->artisan('fatoora:onboard', ['--step' => 'submit', '--target' => 'sandbox'])
            ->assertFailed();
    }

    /**
     * And without credentials it says which step to run rather than failing
     * somewhere further in with something about a certificate.
     */
    public function test_missing_credentials_name_the_step(): void
    {
        File::delete($this->directory.'/pcsid_token.txt');

        $this->artisan('fatoora:onboard', ['--step' => 'submit', '--target' => 'sandbox'])
            ->expectsOutputToContain('Run --step=pcsid first.')
            ->assertFailed();
    }
}
