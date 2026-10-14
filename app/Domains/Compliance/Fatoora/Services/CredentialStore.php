<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\DeviceCredentials;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Helpers\CertificatePem;
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
 * The disk is configuration rather than a constant, so credentials can live on
 * shared storage. On a container-local disk a tenant onboarded on one replica
 * cannot sign on another, which caps the platform at a single replica.
 *
 * Reading and writing live here rather than at each call site, because
 * rotation has to find every stored credential and re-encrypt it, and it
 * cannot do that if callers build their own paths.
 *
 * The secret is fatoora.signing.key, falling back to APP_KEY — see cipher().
 * One secret covers every tenant; a per-tenant data key wrapped by a KMS is
 * what would narrow that, and cipher() is the only place it would change.
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
     * Read from the credential store, which is where onboarding writes.
     */
    public function certificate(string $organizationId, ?string $branchId = null): ?string
    {
        return CertificatePem::from($this->signing($organizationId, $branchId)['pcsid'] ?? null);
    }

    /**
     * The credentials to sign with: the branch's own, or the organization's.
     *
     * The same resolution order certificate() describes, returning the whole
     * credential rather than just the certificate, because signing needs the
     * private key with it. Extracted because OfflineFallback was resolving
     * credentials with a plain get() on the branch and no fallback, so a
     * branch holding none of its own re-signed the document unsigned while
     * the organization had a PCSID the signing path would have used.
     *
     * @return array<string, mixed>|null
     */
    public function signing(string $organizationId, ?string $branchId = null): ?array
    {
        if ($branchId !== null) {
            $branch = $this->get($organizationId, $branchId, self::PCSID);

            if (! empty($branch['pcsid'])) {
                return $branch;
            }
        }

        return $this->get($organizationId, null, self::PCSID);
    }

    /**
     * What a device authenticates to ZATCA with: the branch's own, or the
     * organization's - the order signing resolves in, because the device that
     * signed a document is the one that files it.
     *
     * $type is PCSID for clearance and reporting, CCSID for a compliance
     * check. Each record holds the token under its own type's name.
     *
     * @throws FatooraException When the device has not been onboarded, or was
     *                          onboarded before its secret was kept
     */
    public function device(string $organizationId, ?string $branchId, string $type = self::PCSID): DeviceCredentials
    {
        $stored = null;

        if ($branchId !== null) {
            $branch = $this->get($organizationId, $branchId, $type);
            $stored = ! empty($branch[$type]) ? $branch : null;
        }

        $stored ??= $this->get($organizationId, null, $type);

        if (empty($stored[$type]) || empty($stored['secret'])) {
            throw new FatooraException(
                'No '.($type === self::PCSID ? 'production' : 'compliance').' certificate and secret '
                .'for this device. Complete ZATCA onboarding before filing.',
                ErrorCode::CERT_NOT_FOUND,
                ['org_id' => $organizationId, 'branch_id' => $branchId, 'type' => $type],
            );
        }

        return new DeviceCredentials((string) $stored[$type], (string) $stored['secret']);
    }

    /**
     * What the device that signed a document files it with.
     *
     * Exactly that device, with no fallback. The document carries its
     * signature, and ZATCA checks the filer against it: a branch onboarded
     * after head office signed an invoice must not file that invoice as
     * itself, and a branch whose credentials are gone must not quietly file
     * as head office. $deviceId is the organization's id for head office.
     *
     * An invoice issued before devices were recorded has none, and is
     * resolved the way it was signed then.
     *
     * @throws FatooraException When the device has no credentials
     */
    public function forInvoice(string $organizationId, ?string $deviceId, ?string $branchId): DeviceCredentials
    {
        if ($deviceId === null) {
            return $this->device($organizationId, $branchId);
        }

        $stored = $this->get($organizationId, $deviceId === $organizationId ? null : $deviceId, self::PCSID);

        if (empty($stored[self::PCSID]) || empty($stored['secret'])) {
            throw new FatooraException(
                'No production certificate and secret for the device that signed this invoice. '
                .'It has to be filed by that device: restore its credentials rather than re-onboarding another.',
                ErrorCode::CERT_NOT_FOUND,
                ['org_id' => $organizationId, 'device_id' => $deviceId],
            );
        }

        return new DeviceCredentials((string) $stored[self::PCSID], (string) $stored['secret']);
    }

    /**
     * Remove one branch's credentials, or the organization's own.
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
     * Branch credentials sit under the branch; an organization's own sit
     * directly under it. path() builds both shapes, and both are written:
     * BranchOnboardingController stores the first, OnboardingController the
     * second.
     */
    private function path(string $organizationId, ?string $branchId, string $type): string
    {
        return $branchId === null
            ? "zatca/{$organizationId}/{$type}.json"
            : "zatca/{$organizationId}/branches/{$branchId}/{$type}.json";
    }
}
