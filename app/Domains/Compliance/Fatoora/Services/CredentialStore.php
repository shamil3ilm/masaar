<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Stores the CSID credentials a taxpayer signs invoices with.
 *
 * These are the private keys behind every invoice this platform stamps, so
 * three things about them are deliberate.
 *
 * The disk is configuration, not a constant. It was Storage::disk('local') in
 * three separate files, which meant credentials landed inside the container:
 * a tenant onboarded on one replica could not sign on another, so the platform
 * could not run more than one. Pointing fatoora.signing.disk at a shared
 * filesystem is now the whole change.
 *
 * Reading and writing live here rather than at each call site, because
 * rotation needs to find every stored credential and re-encrypt it, and it
 * cannot do that while three files each build their own paths.
 *
 * The secret is fatoora.signing.key, falling back to APP_KEY — see cipher().
 * One secret still covers every tenant, which is the part of audit finding H-1
 * that remains: a per-tenant data key wrapped by a KMS. cipher() is where that
 * goes, and nothing outside this class changes when it does.
 */
class CredentialStore
{
    /** CSID issued for the compliance checks during onboarding. */
    public const CCSID = 'ccsid';

    /** Production CSID: what signs invoices for real. */
    public const PCSID = 'pcsid';

    public function disk(): Filesystem
    {
        return Storage::disk(config('fatoora.signing.disk', 'local'));
    }

    /**
     * The cipher these credentials are encrypted under.
     *
     * Separate from Crypt on purpose. APP_KEY also protects sessions and
     * cookies, sits in every container and every queue worker, and is on any
     * machine holding a production .env — a blast radius that should not
     * include the private half of a taxpayer's non-repudiation. Setting
     * fatoora.signing.key narrows it, and means routine APP_KEY rotation no
     * longer touches signing material.
     *
     * With no key configured this is Crypt, so nothing breaks by default.
     * Previous keys are accepted for decryption only, which is what lets
     * masaar:rotate-credential-key read old files and write new ones.
     */
    private function cipher(): Encrypter
    {
        $key = (string) config('fatoora.signing.key', '');

        if ($key === '') {
            return Crypt::getFacadeRoot();
        }

        $encrypter = new Encrypter(self::binaryKey($key), config('app.cipher'));

        $previous = array_map(
            static fn (string $k): string => self::binaryKey($k),
            (array) config('fatoora.signing.previous_keys', [])
        );

        return $previous === [] ? $encrypter : $encrypter->previousKeys($previous);
    }

    /**
     * Accept a key written either as raw bytes or in Laravel's base64: form.
     */
    private static function binaryKey(string $key): string
    {
        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $organizationId, ?string $branchId, string $type, array $data): void
    {
        $this->disk()->put(
            $this->path($organizationId, $branchId, $type),
            $this->cipher()->encryptString(json_encode($data, JSON_THROW_ON_ERROR))
        );
    }

    /**
     * @return array<string, mixed>|null Null when nothing is stored, which is
     *                                   not the same as stored-but-empty.
     */
    public function get(string $organizationId, ?string $branchId, string $type): ?array
    {
        $path = $this->path($organizationId, $branchId, $type);

        if (! $this->disk()->exists($path)) {
            return null;
        }

        return json_decode(
            $this->cipher()->decryptString((string) $this->disk()->get($path)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function has(string $organizationId, ?string $branchId, string $type): bool
    {
        return $this->disk()->exists($this->path($organizationId, $branchId, $type));
    }

    /**
     * The certificate an organization signs with, or null before onboarding.
     *
     * A branch is its own EGS unit with its own certificate, so a branch is
     * asked for first and the organization's is the fallback — the same order
     * the signing code resolves in.
     *
     * Callers used to ask a certificate_lineage table that nothing ever wrote,
     * so every one of them concluded there was no certificate.
     */
    public function certificate(string $organizationId, ?string $branchId = null): ?string
    {
        if ($branchId !== null) {
            $branch = $this->get($organizationId, $branchId, self::PCSID);

            if (! empty($branch['pcsid'])) {
                return $branch['pcsid'];
            }
        }

        return $this->get($organizationId, null, self::PCSID)['pcsid'] ?? null;
    }

    /**
     * Remove one branch's credentials, or an organization's legacy pair.
     */
    public function forget(string $organizationId, ?string $branchId = null): void
    {
        foreach ([self::CCSID, self::PCSID] as $type) {
            $path = $this->path($organizationId, $branchId, $type);

            if ($this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * Every stored credential file.
     *
     * Rotation re-encrypts what it finds here, so a credential this misses
     * stays readable only under the old key and its tenant stops signing.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_filter(
            $this->disk()->allFiles('zatca'),
            fn (string $path) => str_ends_with($path, '.json')
        ));
    }

    /**
     * Read and re-encrypt one file in place, under whatever key is current.
     *
     * @return bool False when the file could not be read, so the caller can
     *              report it rather than leave a silent gap.
     */
    public function reencrypt(string $path): bool
    {
        try {
            $plain = $this->cipher()->decryptString((string) $this->disk()->get($path));
        } catch (\Throwable) {
            return false;
        }

        $this->disk()->put($path, $this->cipher()->encryptString($plain));

        return true;
    }

    /**
     * Branch credentials sit under the branch; an organization's pre-branch
     * pair sits directly under it. Both are still read, so onboarding done
     * before branches existed keeps working.
     */
    private function path(string $organizationId, ?string $branchId, string $type): string
    {
        return $branchId === null
            ? "zatca/{$organizationId}/{$type}.json"
            : "zatca/{$organizationId}/branches/{$branchId}/{$type}.json";
    }
}
