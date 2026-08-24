<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

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
     * Bare 'openssl' first so a binary on PATH wins over any guess below it.
     */
    private function findOpenSsl(): ?string
    {
        $candidates = [
            'openssl',
            'C:\\laragon\\bin\\git\\usr\\bin\\openssl.exe',
            'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
            'C:\\Program Files\\Git\\mingw64\\bin\\openssl.exe',
            'C:\\laragon\\bin\\openssl\\openssl.exe',
            'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe',
            'C:\\OpenSSL-Win64\\bin\\openssl.exe',
        ];

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
