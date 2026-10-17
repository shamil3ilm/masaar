<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * --target is the environment for the whole run, not only the endpoint.
 *
 * fatoora:onboard --target=sandbox sent to the sandbox while everything else
 * read fatoora.environment. The signer refuses a key that does not match its
 * certificate everywhere but the sandbox - where ZATCA issues every caller
 * the same certificate, whose key nobody has - and it asked the configuration
 * which environment it was in. Once .env.example said production, as a
 * deployment's must, the nightly round trip ran with a production
 * configuration and a sandbox target, and the signer refused the sandbox's
 * own certificate: "the private key does not match". Locally .env said
 * sandbox, so it passed there and failed only in CI.
 */
class OnboardTargetTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/masaar-onboard-'.uniqid();
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

    public function test_the_target_decides_the_environment(): void
    {
        config(['fatoora.environment' => 'production']);

        // The sandbox's shape: a certificate whose key is not the one held.
        $shared = $this->selfSignedCredentials();
        $ours = $this->selfSignedCredentials();

        file_put_contents($this->storage.'/app/zatca/pcsid_token.txt', base64_encode($shared['certificate']));
        file_put_contents($this->storage.'/app/zatca/pcsid_secret.txt', 'sandbox-secret');
        file_put_contents($this->storage.'/app/zatca/pcsid_certificate.pem', $shared['certificate']);
        file_put_contents($this->storage.'/app/zatca/ccsid_private_key.pem', $ours['privateKey']);

        Http::fake([
            '*/invoices/clearance/*' => Http::response([
                'clearanceStatus' => 'CLEARED',
                'validationResults' => ['status' => 'PASS'],
                'clearedInvoice' => base64_encode('<Invoice/>'),
            ]),
            '*/invoices/reporting/*' => Http::response([
                'reportingStatus' => 'REPORTED',
                'validationResults' => ['status' => 'PASS'],
            ]),
        ]);

        $this->artisan('fatoora:onboard', ['--step' => 'submit', '--target' => 'sandbox'])
            ->doesntExpectOutputToContain('does not match')
            ->assertSuccessful();
    }
}
