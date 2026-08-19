<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The store holding every taxpayer's signing keys.
 *
 * Two properties matter beyond "it round-trips". The disk has to be the one
 * configuration names, because hard-coding it confined the platform to a
 * single replica. And rotation has to find every file, because one it misses
 * stays encrypted under the retired key and that tenant silently stops being
 * able to sign.
 */
class CredentialStoreTest extends TestCase
{
    private CredentialStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('credentials');
        config(['fatoora.signing.disk' => 'credentials']);

        $this->store = new CredentialStore;
    }

    private const ORG = 'org-1';

    private const BRANCH = 'branch-1';

    /**
     * @return array<string, string>
     */
    private function pcsid(string $marker = 'KEY'): array
    {
        return ['privateKey' => "-----BEGIN EC PRIVATE KEY-----{$marker}", 'pcsid' => 'CERT'];
    }

    public function test_certificate_is_null_before_onboarding(): void
    {
        $this->assertNull($this->store->certificate(self::ORG));
    }

    public function test_certificate_falls_back_to_the_organization(): void
    {
        $this->store->put(self::ORG, null, CredentialStore::PCSID, $this->pcsid());

        $this->assertSame('CERT', $this->store->certificate(self::ORG, self::BRANCH));
    }

    /**
     * A branch is its own EGS unit with its own certificate, so its own is the
     * one it signs with.
     */
    public function test_branch_certificate_wins_over_the_organization(): void
    {
        $this->store->put(self::ORG, null, CredentialStore::PCSID, $this->pcsid());
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, [
            'privateKey' => '-----BEGIN EC PRIVATE KEY-----BRANCH',
            'pcsid' => 'BRANCH-CERT',
        ]);

        $this->assertSame('BRANCH-CERT', $this->store->certificate(self::ORG, self::BRANCH));
        $this->assertSame('CERT', $this->store->certificate(self::ORG));
    }

    public function test_round_trips_branch_credentials(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }

    /**
     * Onboarding done before branches existed stored directly under the
     * organization, and those tenants must keep signing.
     */
    public function test_round_trips_legacy_credentials(): void
    {
        $this->store->put(self::ORG, null, CredentialStore::PCSID, $this->pcsid('LEGACY'));

        $this->assertSame(
            $this->pcsid('LEGACY'),
            $this->store->get(self::ORG, null, CredentialStore::PCSID)
        );
    }

    public function test_uses_the_configured_disk(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        Storage::disk('credentials')
            ->assertExists('zatca/'.self::ORG.'/branches/'.self::BRANCH.'/pcsid.json');
    }

    /**
     * A key on disk in clear would be readable by anything with filesystem
     * access, including a backup.
     */
    public function test_stored_bytes_are_not_plaintext(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        $raw = Storage::disk('credentials')
            ->get('zatca/'.self::ORG.'/branches/'.self::BRANCH.'/pcsid.json');

        $this->assertStringNotContainsString('BEGIN EC PRIVATE KEY', (string) $raw);
    }

    public function test_missing_credentials_read_as_null(): void
    {
        $this->assertNull($this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID));
    }

    public function test_forget_removes_both_types(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::CCSID, ['a' => 1]);
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, ['b' => 2]);

        $this->store->forget(self::ORG, self::BRANCH);

        $this->assertFalse($this->store->has(self::ORG, self::BRANCH, CredentialStore::CCSID));
        $this->assertFalse($this->store->has(self::ORG, self::BRANCH, CredentialStore::PCSID));
    }

    /**
     * Rotation re-encrypts what paths() returns, so anything it omits is a
     * tenant that stops signing when the old key is withdrawn.
     */
    public function test_paths_finds_branch_and_legacy(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());
        $this->store->put(self::ORG, null, CredentialStore::CCSID, ['legacy' => true]);
        $this->store->put('org-2', 'branch-9', CredentialStore::PCSID, $this->pcsid());

        $this->assertCount(3, $this->store->paths());
    }

    public function test_reencrypt_preserves_contents(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());
        $path = 'zatca/'.self::ORG.'/branches/'.self::BRANCH.'/pcsid.json';

        $before = Storage::disk('credentials')->get($path);

        $this->assertTrue($this->store->reencrypt($path));

        // Same plaintext, different ciphertext — the IV changes per encrypt,
        // so an unchanged blob would mean nothing was rewritten.
        $this->assertNotSame($before, Storage::disk('credentials')->get($path));
        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }

    public function test_reencrypt_reports_unreadable_file(): void
    {
        Storage::disk('credentials')->put('zatca/org-3/pcsid.json', 'not encrypted');

        $this->assertFalse($this->store->reencrypt('zatca/org-3/pcsid.json'));
    }

    public function test_rotate_command_reports_totals(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());
        $this->store->put('org-2', null, CredentialStore::CCSID, ['x' => 1]);

        $this->artisan('masaar:rotate-credential-key')
            ->assertSuccessful();

        // Still readable afterwards, which is the point of the exercise.
        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }

    public function test_rotate_command_fails_on_unreadable(): void
    {
        Storage::disk('credentials')->put('zatca/org-4/pcsid.json', 'garbage');

        $this->artisan('masaar:rotate-credential-key')->assertFailed();
    }

    public function test_dry_run_leaves_files_untouched(): void
    {
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());
        $path = 'zatca/'.self::ORG.'/branches/'.self::BRANCH.'/pcsid.json';
        $before = Storage::disk('credentials')->get($path);

        $this->artisan('masaar:rotate-credential-key', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($before, Storage::disk('credentials')->get($path));
    }

    /**
     * The whole point of the command: a credential written under the old key
     * is still readable after the old key is withdrawn.
     *
     * Without the rotation step in the middle, this fails — which is the
     * outage APP_KEY rotation causes today.
     */
    public function test_rotation_survives_key_withdrawal(): void
    {
        $oldKey = config('app.key');
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        // New key active, old one still accepted for reads.
        $this->swapKey($newKey, [$oldKey]);

        $this->artisan('masaar:rotate-credential-key')->assertSuccessful();

        // Old key withdrawn. Only the re-encrypted copy can be read now.
        $this->swapKey($newKey, []);

        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }

    /**
     * @param  list<string>  $previous
     */
    private function swapKey(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);

        // The encrypter is resolved once at boot, so the container has to be
        // told to build a new one against the changed config.
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstances();
        app()->register(EncryptionServiceProvider::class, true);
    }

    /**
     * A dedicated signing secret means APP_KEY rotation stops being able to
     * lock every tenant out of signing.
     */
    public function test_dedicated_key_is_used_when_set(): void
    {
        $signingKey = 'base64:'.base64_encode(random_bytes(32));
        config(['fatoora.signing.key' => $signingKey]);

        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        // APP_KEY moves; the credential was never encrypted under it.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }

    /**
     * Withdrawing the old signing secret without rotating first is the outage
     * this whole arrangement exists to make survivable.
     */
    public function test_signing_key_rotation_survives_withdrawal(): void
    {
        $old = 'base64:'.base64_encode(random_bytes(32));
        $new = 'base64:'.base64_encode(random_bytes(32));

        config(['fatoora.signing.key' => $old, 'fatoora.signing.previous_keys' => []]);
        $this->store->put(self::ORG, self::BRANCH, CredentialStore::PCSID, $this->pcsid());

        config(['fatoora.signing.key' => $new, 'fatoora.signing.previous_keys' => [$old]]);
        $this->artisan('masaar:rotate-credential-key')->assertSuccessful();

        config(['fatoora.signing.previous_keys' => []]);

        $this->assertSame(
            $this->pcsid(),
            $this->store->get(self::ORG, self::BRANCH, CredentialStore::PCSID)
        );
    }
}
