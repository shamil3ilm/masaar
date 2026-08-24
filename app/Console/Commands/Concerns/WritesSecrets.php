<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Writing a private key or a CSID secret to disk.
 *
 * The onboarding commands put the taxpayer's signing key, the CSID tokens and
 * their secrets under storage/app/zatca. They were written through File::put
 * into a directory made 0755, so on any host with more than one account the
 * key that signs this taxpayer's invoices was readable by all of them.
 *
 * Nothing here makes disk the right home for a secret — the platform's own
 * credentials live encrypted in CredentialStore. It makes the developer path
 * stop being the weak one.
 */
trait WritesSecrets
{
    /**
     * The directory these commands keep their working files in, owner-only.
     *
     * Defaults to storage/app/zatca; fatoora:generate-csr lets --output name
     * somewhere else, and that directory is created the same way.
     */
    protected function secretDir(?string $path = null): string
    {
        $dir = $path ?? storage_path('app/zatca');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * Write a secret, readable by its owner and no one else.
     */
    protected function putSecret(string $path, string $contents): void
    {
        File::put($path, $contents);

        // Windows has no mode to set; chmod there reports success and changes
        // nothing, which is why this is not asserted on that platform.
        @chmod($path, 0600);
    }
}
