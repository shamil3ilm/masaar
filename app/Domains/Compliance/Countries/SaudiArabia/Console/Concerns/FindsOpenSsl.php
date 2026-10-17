<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console\Concerns;

/**
 * Locate the OpenSSL binary the onboarding commands shell out to.
 *
 * One implementation, because the failure modes are easy to get subtly wrong:
 * $output has to be reset between probes or exec appends and the failure text
 * accumulates every candidate tried, and the path has to be quoted or the
 * candidates under "C:\Program Files" can never match.
 */
trait FindsOpenSsl
{
    /**
     * The first candidate that answers `version`, or null if none does.
     *
     * OPENSSL_BIN when it is set, then `openssl` on the PATH. This used to
     * guess at install directories on one developer's machine, which found
     * nothing anywhere else and said nothing about how to fix it.
     */
    private function findOpenSsl(): ?string
    {
        $candidates = array_values(array_filter([
            config('fatoora.openssl_binary'),
            'openssl',
        ], fn ($path): bool => is_string($path) && $path !== ''));

        foreach ($candidates as $path) {
            // Quoted: half these paths contain a space. Reset per probe: exec
            // appends to $output rather than replacing it.
            $output = [];
            exec("\"{$path}\" version 2>&1", $output, $code);

            if ($code === 0) {
                return $path;
            }
        }

        return null;
    }
}
