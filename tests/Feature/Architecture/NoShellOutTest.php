<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps the request and queue paths free of shelled-out commands.
 *
 * Two things go wrong when a domain service runs a binary. The obvious one is
 * the undeclared dependency: nothing checks the container has `openssl` until
 * a submission fails. The worse one is that the answer comes back as text
 * written for a person, and code then decides something security-relevant by
 * matching strings in it — so an OpenSSL upgrade, a translated locale, or an
 * error message that happens to contain the word being searched for changes
 * the verdict without anything about the certificate changing.
 *
 * Console commands are exempt. They are operator tooling, run deliberately by
 * someone who can read the failure, and several exist precisely to drive the
 * ZATCA Java SDK.
 */
class NoShellOutTest extends TestCase
{
    private const DOMAINS = __DIR__.'/../../../app/Domains';

    /**
     * curl_exec is the cURL extension, not a shell; the leading boundary keeps
     * it and any other *_exec function out of the match.
     */
    private const SHELL_FUNCTIONS = [
        'shell_exec',
        'exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
    ];

    public function test_domains_do_not_shell_out(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $path) {
            $body = (string) file_get_contents($path);

            foreach (self::SHELL_FUNCTIONS as $function) {
                if (preg_match('/(?<![a-z0-9_])'.$function.'\s*\(/i', $body)) {
                    $offenders[] = basename($path)." calls {$function}()";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders)."\n\n".
            'Domain services must not run external commands. Parse the data '.
            'directly — phpseclib covers X.509, CRL and ASN.1.');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DOMAINS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
