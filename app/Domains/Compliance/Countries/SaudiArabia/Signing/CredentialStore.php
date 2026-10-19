<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\ChainRecorder;
use App\Domains\Compliance\Countries\SaudiArabia\Models\CertificateVersion;
use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Support\Errors\ErrorCode;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *
 * A device's production certificate is versioned. pcsid.json is the one it
 * signs with now; each one it held before is kept beside it, encrypted the
 * same way, under certificates/, and device_certificates says which is which.
 * A document is filed with the version that signed it - see forInvoice().
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
     * Store a credential. A production certificate other than the one held
     * is a renewal: the one held is kept as a version, never overwritten.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(string $organizationId, ?string $branchId, string $type, array $data): void
    {
        $held = $type === self::PCSID && $this->keepHeld($organizationId, $branchId, $data);

        $this->write($this->path($organizationId, $branchId, $type), $data);

        if ($held || ($type === self::PCSID && ! empty($data[self::PCSID]))) {
            $this->versions($organizationId, $branchId);
        }
    }

    /**
     * @return array<string, mixed>|null Null when nothing is stored, which is
     *                                   not the same as stored-but-empty.
     */
    public function get(string $organizationId, ?string $branchId, string $type): ?array
    {
        return $this->read($this->path($organizationId, $branchId, $type));
    }

    /**
     * Every production certificate the device has held, oldest first.
     *
     * Reconciled with what the device holds on the way: a credential stored
     * before versions were kept becomes version 1 as it is, and one written
     * without its row - a write that died halfway - gets its row. The file is
     * what the device signs with, so the rows follow it.
     *
     * @return Collection<int, CertificateVersion>
     */
    public function versions(string $organizationId, ?string $branchId = null): Collection
    {
        $deviceId = $branchId ?? $organizationId;
        $held = $this->get($organizationId, $branchId, self::PCSID);
        $identity = empty($held[self::PCSID]) ? null : CertificateIdentity::of((string) $held[self::PCSID]);

        return CertificateVersion::withoutTenantScope(fn (): Collection => DB::transaction(function () use ($organizationId, $deviceId, $identity): Collection {
            $rows = CertificateVersion::query()->where('device_id', $deviceId)->orderBy('version')->lockForUpdate()->get();
            $current = $identity === null ? null : $rows->firstWhere('certificate_id', $identity->id);

            foreach ($rows as $row) {
                if ($row->status === CertificateVersion::ACTIVE && ! $row->is($current)) {
                    $row->update(['status' => CertificateVersion::SUPERSEDED]);
                }
            }

            // Held again after being replaced. A revoked one stays revoked.
            if ($current?->status === CertificateVersion::SUPERSEDED) {
                $current->update(['status' => CertificateVersion::ACTIVE]);
            }

            if ($identity !== null && $current === null) {
                $rows->push(CertificateVersion::query()->create([
                    'org_id' => $organizationId,
                    'device_id' => $deviceId,
                    'version' => (int) $rows->max('version') + 1,
                    'certificate_id' => $identity->id,
                    'serial_number' => $identity->serialNumber,
                    'issuer' => $identity->issuer,
                    'valid_from' => $identity->validFrom,
                    'valid_to' => $identity->validTo,
                    'status' => CertificateVersion::ACTIVE,
                ]));
            }

            return $rows->values();
        }));
    }

    /**
     * One version's stored credential: certificate, secret and private key.
     *
     * @return array<string, mixed>|null Null when that version's credential
     *                                   is not stored
     */
    public function record(string $organizationId, ?string $branchId, string $certificateId): ?array
    {
        $held = $this->get($organizationId, $branchId, self::PCSID);

        if (! empty($held[self::PCSID]) && CertificateIdentity::of((string) $held[self::PCSID])->id === $certificateId) {
            return $held;
        }

        return $this->read($this->versionPath($organizationId, $branchId, $certificateId));
    }

    /**
     * Record that a certificate was revoked. Nothing is filed with it again,
     * and if the device signs with it, it stops: the device has no
     * production certificate until it is onboarded again. The version keeps
     * why, and who revoked it: nobody when the CA's list said so.
     *
     * @throws FatooraException When the device never held that certificate
     */
    public function revoke(string $organizationId, ?string $branchId, string $certificateId, ?string $reason = null, ?string $revokedBy = null): void
    {
        $version = $this->versions($organizationId, $branchId)->firstWhere('certificate_id', $certificateId)
            ?? throw new FatooraException(
                'This device never held that certificate, so there is nothing to revoke.',
                ErrorCode::CERT_NOT_FOUND,
                ['org_id' => $organizationId, 'branch_id' => $branchId],
            );

        $held = $this->get($organizationId, $branchId, self::PCSID);
        $signsWithIt = ! empty($held[self::PCSID]) && CertificateIdentity::of((string) $held[self::PCSID])->id === $certificateId;

        if ($signsWithIt) {
            $this->keepAsVersion($organizationId, $branchId, $certificateId, $held);
        }

        CertificateVersion::withoutTenantScope(fn () => $version->forceFill([
            'status' => CertificateVersion::REVOKED,
            'revoked_reason' => $reason,
            'revoked_by' => $revokedBy,
        ])->save());

        if ($signsWithIt) {
            $this->disk()->delete($this->path($organizationId, $branchId, self::PCSID));
        }
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

            $this->assertNotRevoked($organizationId, $branchId);
        }

        $organization = $this->get($organizationId, null, self::PCSID);

        if (empty($organization['pcsid'])) {
            $this->assertNotRevoked($organizationId, null);
        }

        return $organization;
    }

    /**
     * Refuse to sign for a device whose certificate was revoked and that
     * holds no other.
     *
     * Without this a branch whose certificate was revoked fell back to head
     * office's and signed as another device, and an organization's signed
     * nothing at all - an unsigned document, said nowhere.
     *
     * @throws FatooraException
     */
    public function assertNotRevoked(string $organizationId, ?string $branchId): void
    {
        $latest = CertificateVersion::withoutTenantScope(fn () => CertificateVersion::query()
            ->where('device_id', $branchId ?? $organizationId)
            ->orderByDesc('version')
            ->first());

        if ($latest?->status === CertificateVersion::REVOKED) {
            throw new FatooraException(
                __('This device\'s ZATCA certificate has been revoked, so it cannot sign invoices. Onboard the device again to obtain a new certificate.'),
                ErrorCode::CERT_REVOKED,
                ['org_id' => $organizationId, 'branch_id' => $branchId, 'version' => $latest->version],
            );
        }
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
     * as head office. The invoice's device_id is the organization's id for
     * head office.
     *
     * And exactly the certificate that signed it. A device renewed since then
     * holds a different one, and a document still queued, retried or reported
     * late is filed with the CSID and secret it was stamped under - the
     * version its chain entry names. Every filing path asks here.
     *
     * An invoice issued before devices were recorded has none, and is
     * resolved the way it was signed then.
     *
     * @param  string|null  $deviceId  The invoice's device_id
     * @param  string|null  $branchId  The invoice's branch_id
     * @param  string|null  $signedXml  The document being filed
     *
     * @throws FatooraException When the device has no credentials, or the
     *                          certificate that signed it was revoked
     */
    public function forInvoice(string $organizationId, ?string $deviceId, ?string $branchId, string $invoiceId, ?string $signedXml): DeviceCredentials
    {
        if ($deviceId === null) {
            return $this->device($organizationId, $branchId);
        }

        $stored = $this->signedWith($organizationId, $deviceId === $organizationId ? null : $deviceId, $invoiceId, $signedXml);

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
     *
     * The device stops holding a production certificate, but the one it held
     * is kept as a superseded version rather than deleted: a reset is how a
     * branch is re-onboarded, and what it signed before still has to be filed
     * with it.
     */
    public function forget(string $organizationId, ?string $branchId = null): void
    {
        $held = $this->get($organizationId, $branchId, self::PCSID);
        $heldCertificate = ! empty($held[self::PCSID]);

        if ($heldCertificate) {
            $this->versions($organizationId, $branchId);
            $this->keepAsVersion($organizationId, $branchId, CertificateIdentity::of((string) $held[self::PCSID])->id, $held);
        }

        foreach ([self::CCSID, self::PCSID] as $type) {
            $path = $this->path($organizationId, $branchId, $type);

            if ($this->disk()->exists($path)) {
                $this->disk()->delete($path);
            }
        }

        if ($heldCertificate) {
            $this->versions($organizationId, $branchId);
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
     * The credential of the version that signed this invoice.
     *
     * The chain entry names the certificate, as ChainRecorder recorded it at
     * issue. A document with none - signed by the offline path, or before the
     * chain was recorded - is matched on the certificate it carries. One that
     * names a certificate this device has no version of is filed with what
     * the device holds, as every document was before versions were kept, and
     * logged: there is nothing better to file it with.
     *
     * @return array<string, mixed>|null
     *
     * @throws FatooraException When that version was revoked
     */
    private function signedWith(string $organizationId, ?string $branchId, string $invoiceId, ?string $signedXml): ?array
    {
        $versions = $this->versions($organizationId, $branchId);

        $recorded = ChainEntry::withoutTenantScope(
            fn () => ChainEntry::query()->where('invoice_id', $invoiceId)->value('certificate_id')
        );
        $recorded = $recorded === ChainRecorder::NO_CERTIFICATE ? null : $recorded;

        $version = $recorded === null ? null : $versions->firstWhere('certificate_id', $recorded);

        if ($version === null && ($carried = CertificateIdentity::ofDocument($signedXml)) !== null) {
            $version = $versions->first(fn (CertificateVersion $v): bool => $carried->id === $v->certificate_id
                || ($carried->serialNumber !== null && $carried->serialNumber !== ''
                    && $carried->serialNumber === $v->serial_number && $carried->issuer === $v->issuer));
        }

        if ($version === null) {
            if ($recorded !== null || $signedXml !== null) {
                Log::warning('No stored certificate version matches the one that signed this invoice; filing with the one the device holds', [
                    'org_id' => $organizationId,
                    'invoice_id' => $invoiceId,
                    'device_id' => $branchId ?? $organizationId,
                ]);
            }

            return $this->get($organizationId, $branchId, self::PCSID);
        }

        if ($version->status === CertificateVersion::REVOKED) {
            throw new FatooraException(
                'This invoice was signed with a certificate that has since been revoked, '
                .'so it cannot be filed with it. Nothing was sent to ZATCA.',
                ErrorCode::CERT_REVOKED,
                ['org_id' => $organizationId, 'invoice_id' => $invoiceId, 'device_id' => $branchId ?? $organizationId, 'version' => $version->version],
            );
        }

        return $this->record($organizationId, $branchId, $version->certificate_id);
    }

    /**
     * Before a different production certificate replaces the one held, keep
     * the one held as its version, so its secret and key are not lost.
     *
     * @param  array<string, mixed>  $incoming
     * @return bool Whether the device held a production certificate
     */
    private function keepHeld(string $organizationId, ?string $branchId, array $incoming): bool
    {
        $held = $this->get($organizationId, $branchId, self::PCSID);

        if (empty($held[self::PCSID])) {
            return false;
        }

        $heldId = CertificateIdentity::of((string) $held[self::PCSID])->id;

        if (! empty($incoming[self::PCSID]) && CertificateIdentity::of((string) $incoming[self::PCSID])->id === $heldId) {
            return true;
        }

        $this->versions($organizationId, $branchId);
        $this->keepAsVersion($organizationId, $branchId, $heldId, $held);

        return true;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function keepAsVersion(string $organizationId, ?string $branchId, string $certificateId, array $record): void
    {
        $path = $this->versionPath($organizationId, $branchId, $certificateId);

        if (! $this->disk()->exists($path)) {
            $this->write($path, $record);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $path, array $data): void
    {
        $this->disk()->put($path, $this->cipher()->encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
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

    /**
     * Branch credentials sit under the branch; an organization's own sit
     * directly under it. path() builds both shapes, and both are written:
     * BranchOnboardingController stores the first, OnboardingController the
     * second.
     */
    private function path(string $organizationId, ?string $branchId, string $type): string
    {
        return $this->directory($organizationId, $branchId)."/{$type}.json";
    }

    /**
     * A production certificate the device no longer signs with, by the
     * digest its chain entries carry. Under zatca/ like the rest, so rotation
     * re-encrypts it with them.
     */
    private function versionPath(string $organizationId, ?string $branchId, string $certificateId): string
    {
        return $this->directory($organizationId, $branchId)."/certificates/{$certificateId}.json";
    }

    private function directory(string $organizationId, ?string $branchId): string
    {
        return $branchId === null
            ? "zatca/{$organizationId}"
            : "zatca/{$organizationId}/branches/{$branchId}";
    }
}
