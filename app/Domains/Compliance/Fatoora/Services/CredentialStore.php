<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
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
 * could not run more than one. Pointing fatoora.credentials.disk at a shared
 * filesystem is now the whole change.
 *
 * Reading and writing live here rather than at each call site, because
 * rotation needs to find every stored credential and re-encrypt it, and it
 * cannot do that while three files each build their own paths.
 *
 * Encryption is Laravel's, so the key is APP_KEY and one key covers every
 * tenant. That is the remaining gap — audit finding H-1 — and closing it means
 * a per-tenant data key wrapped by a KMS. This class is where that goes, and
 * nothing outside it needs to change when it does.
 */
class CredentialStore
{
    /** CSID issued for the compliance checks during onboarding. */
    public const CCSID = 'ccsid';

    /** Production CSID: what signs invoices for real. */
    public const PCSID = 'pcsid';

    public function disk(): Filesystem
    {
        return Storage::disk(config('fatoora.credentials.disk', 'local'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $organizationId, ?string $branchId, string $type, array $data): void
    {
        $this->disk()->put(
            $this->path($organizationId, $branchId, $type),
            Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))
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
            Crypt::decryptString((string) $this->disk()->get($path)),
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
            $plain = Crypt::decryptString((string) $this->disk()->get($path));
        } catch (\Throwable) {
            return false;
        }

        $this->disk()->put($path, Crypt::encryptString($plain));

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
