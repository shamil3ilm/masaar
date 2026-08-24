<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * One place decides where OpenSSL is.
 *
 * The search list was written three times — twice as a private findOpenSsl and
 * once inline — and the copies drifted, each in a way that was invisible until
 * it mattered. One dropped the reset of $output between probes, so exec
 * appended and the reported failure grew with every candidate tried. The other
 * dropped the quoting, so the two candidates under "C:\Program Files" could
 * never match: the space split the command and OpenSSL installed in its default
 * Windows location was reported as absent.
 *
 * Neither was a typo. They are what happens to a copied helper over time, and
 * the only durable fix is that there is nothing left to copy.
 */
class OpenSslLookupTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    private const HOME = 'Console/Commands/Concerns/FindsOpenSsl.php';

    /**
     * Matched on a path from the list rather than on the function name, because
     * the next copy will not be called findOpenSsl.
     */
    public function test_search_list_is_not_duplicated(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(realpath(self::APP)) + 1));

            if ($relative === self::HOME) {
                continue;
            }

            $source = file_get_contents($file);

            // The .exe candidates specifically: help text naming the directory
            // is guidance for an operator, not a second lookup.
            if (str_contains($source, 'OpenSSL-Win64\\\\bin\\\\openssl.exe')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These files carry their own OpenSSL search list. There is one, in %s, and it is a trait so it can be used rather than copied.\n%s",
            self::HOME,
            implode("\n", $offenders)
        ));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(realpath(self::APP), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
