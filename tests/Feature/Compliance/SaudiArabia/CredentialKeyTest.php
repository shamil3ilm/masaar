<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The secret that protects stored signing credentials.
 *
 * These files hold the private half of a taxpayer's non-repudiation. Under
 * APP_KEY they share a secret with sessions and cookies, present in every
 * container, every queue worker, and any machine holding a production .env.
 * fatoora.signing.key narrows that, and takes signing material out of the path
 * of routine APP_KEY rotation — which would otherwise make every stored
 * credential undecryptable and stop every tenant signing, with no warning
 * beforehand.
 */
class CredentialKeyTest extends TestCase
{
    // The store reads the organization's ZATCA environment.
    use RefreshDatabase;

    private CredentialStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->store = new CredentialStore;
    }

    public function test_credentials_round_trip(): void
    {
        config(['fatoora.signing.key' => $this->key(), 'fatoora.signing.previous_keys' => []]);

        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);

        $this->assertSame('PEM', $this->store->get('org-1', null, CredentialStore::PCSID)['privateKey']);
    }

    /**
     * The decoupling that matters: rotating APP_KEY is routine, and must not
     * take every taxpayer's signing key with it.
     */
    public function test_survives_app_key_rotation(): void
    {
        config(['fatoora.signing.key' => $this->key(), 'fatoora.signing.previous_keys' => []]);

        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);

        config(['app.key' => $this->key()]);

        $this->assertSame('PEM', $this->store->get('org-1', null, CredentialStore::PCSID)['privateKey']);
    }

    public function test_old_files_need_old_key(): void
    {
        config(['fatoora.signing.key' => $this->key(), 'fatoora.signing.previous_keys' => []]);
        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);

        config(['fatoora.signing.key' => $this->key(), 'fatoora.signing.previous_keys' => []]);

        $this->expectException(\Throwable::class);
        $this->store->get('org-1', null, CredentialStore::PCSID);
    }

    /**
     * The full rotation: new key current, old key listed, re-encrypt, drop the
     * old one. Stopping before the last step leaves the platform working but
     * still dependent on the retired secret.
     */
    public function test_rotation_rekeys_files(): void
    {
        $old = $this->key();
        $new = $this->key();

        config(['fatoora.signing.key' => $old, 'fatoora.signing.previous_keys' => []]);
        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);
        $this->store->put('org-2', 'branch-a', CredentialStore::CCSID, ['privateKey' => 'PEM-2']);

        config(['fatoora.signing.key' => $new, 'fatoora.signing.previous_keys' => [$old]]);

        foreach ($this->store->paths() as $path) {
            $this->assertTrue($this->store->reencrypt($path), "could not re-encrypt {$path}");
        }

        config(['fatoora.signing.previous_keys' => []]);

        $this->assertSame('PEM', $this->store->get('org-1', null, CredentialStore::PCSID)['privateKey']);
        $this->assertSame('PEM-2', $this->store->get('org-2', 'branch-a', CredentialStore::CCSID)['privateKey']);
    }

    /**
     * Rotation re-encrypts what paths() returns, so anything it misses stays
     * readable only under the retired key and its tenant stops signing.
     */
    public function test_rotation_finds_branch_credentials(): void
    {
        config(['fatoora.signing.key' => $this->key(), 'fatoora.signing.previous_keys' => []]);

        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'A']);
        $this->store->put('org-1', 'branch-a', CredentialStore::PCSID, ['privateKey' => 'B']);
        $this->store->put('org-1', 'branch-b', CredentialStore::CCSID, ['privateKey' => 'C']);

        $this->assertCount(3, $this->store->paths());
    }

    /**
     * With no dedicated key set an existing deployment keeps working, which is
     * what makes adopting one a decision rather than a migration.
     */
    public function test_falls_back_to_the_application_key(): void
    {
        config(['fatoora.signing.key' => '']);

        $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);

        $this->assertSame('PEM', $this->store->get('org-1', null, CredentialStore::PCSID)['privateKey']);
    }

    /**
     * L6: in production there is no fallback - not in a queue worker or a
     * console command either, which the boot guard does not see - and the
     * application key is not a credential key.
     */
    public function test_production_needs_its_own_key(): void
    {
        config(['app.env' => 'production']);

        foreach (['', (string) config('app.key')] as $key) {
            config(['fatoora.signing.key' => $key]);

            try {
                $this->store->put('org-1', null, CredentialStore::PCSID, ['privateKey' => 'PEM']);
                $this->fail('Credentials were written under the application key in production.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ZATCA_CREDENTIAL_KEY', $e->getMessage());
            }
        }
    }

    private function key(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}
