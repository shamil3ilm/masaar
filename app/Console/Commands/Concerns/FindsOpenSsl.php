<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Locate the OpenSSL binary the onboarding commands shell out to.
 *
 * This was written three times: twice as a private findOpenSsl and once inline,
 * and the copies had drifted in two different directions. One reset $output
 * between probes and one did not, so exec appended and the failure text grew
 * with every candidate tried. The inline one did not quote the path, which
 * means the two candidates under "C:\Program Files" could never match — the
 * space split the command, and OpenSSL installed in its default Windows
 * location was reported as absent.
 *
 * Console commands only. Nothing under app/Domains shells out, and
 * NoShellOutTest keeps it that way.
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
