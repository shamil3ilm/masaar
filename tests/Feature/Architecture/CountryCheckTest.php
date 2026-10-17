<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shared code asks the compliance engine, never "which country is this?".
 *
 * Each country is a module plugged into one engine
 * (docs/architecture/JURISDICTION-ROUTING.md). A literal country comparison
 * in shared code - $organization->country === 'AE', a buyer's country !==
 * 'SA' - is a place where that country's rule lives outside its module, and
 * where adding a country means finding and editing a branch.
 *
 * The list below is a ratchet of file => how many such comparisons it holds,
 * for everything under app/ except the country modules and the engine. The
 * assertion is equality: a new comparison fails until it is listed, and one
 * removed fails until it is delisted, so the checks are seen to shrink.
 * It is empty: every country's rule now sits behind one of the engine's
 * ports, and a new comparison belongs behind one too.
 */
class CountryCheckTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Country modules, the networks they send through, and the engine: where
     * a country's code belongs. ModuleBoundaryTest keeps each of them to its
     * own side.
     */
    private const EXEMPT = [
        'Domains/Compliance/Engine/',
        'Domains/Compliance/Countries/',
        'Domains/Compliance/Networks/',
    ];

    private const ENGINE = 'Domains/Compliance/Engine/';

    /**
     * An equality comparison against a quoted two-letter upper-case code, on
     * either side: === 'AE', !== 'SA', 'AE' == $x.
     */
    private const COMPARISON = '/[!=]==?\s*([\'"])[A-Z]{2}\1|([\'"])[A-Z]{2}\2\s*[!=]==?/';

    /**
     * Files outside the modules comparing against a country code, and how often.
     *
     * @var array<string, int>
     */
    private const DECLARED = [];

    public function test_country_checks_only_shrink(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $source) {
            if ($this->exempt($relative)) {
                continue;
            }

            $count = preg_match_all(self::COMPARISON, $source);

            if ($count > 0) {
                $found[$relative] = $count;
            }
        }

        ksort($found);

        $this->assertSame(self::DECLARED, $found, "Country comparisons in shared code changed. Found:\n".var_export($found, true));
    }

    /**
     * The engine knows no country: it imports no module, and names no code.
     */
    public function test_engine_names_no_country(): void
    {
        $engine = array_filter($this->files(), fn (string $file): bool => str_starts_with($file, self::ENGINE), ARRAY_FILTER_USE_KEY);
        $offenders = [];

        $this->assertNotSame([], $engine, 'The engine was not found.');

        foreach ($engine as $relative => $source) {
            if (preg_match('/App\\\\Domains\\\\Compliance\\\\(Countries|Networks)\\\\/', $source) === 1) {
                $offenders[] = $relative.' references a country or network module';
            }

            if (preg_match('/([\'"])[A-Z]{2}\1/', $source) === 1) {
                $offenders[] = $relative.' names a country code';
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    private function exempt(string $relative): bool
    {
        foreach (self::EXEMPT as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> path relative to app/ => source
     */
    private function files(): array
    {
        $root = str_replace('\\', '/', (string) realpath(self::APP)).'/';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', (string) $file->getRealPath());
                $files[str_replace($root, '', $path)] = (string) file_get_contents($path);
            }
        }

        return $files;
    }
}
