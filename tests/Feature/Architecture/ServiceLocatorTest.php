<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps domain services' dependencies in their constructors.
 *
 * A service that pulls a collaborator out of the container with app() hides a
 * dependency its constructor does not declare, so it cannot be faked where it
 * is built and the wiring is invisible to WiredDependencyTest.
 *
 * The list below is a ratchet: the assertion is equality, so a service that
 * starts resolving from the container fails the build until it is listed, and
 * one that stops fails until it is delisted.
 */
class ServiceLocatorTest extends TestCase
{
    private const DOMAINS = __DIR__.'/../../../app/Domains';

    /**
     * Domain services that still resolve collaborators from the container.
     */
    private const DECLARED = [
        'Domains/Audit/Services/AuditService.php',
        'Domains/Logging/Services/ComplianceLogger.php',
    ];

    public function test_services_take_dependencies_by_injection(): void
    {
        $found = [];

        foreach ($this->services() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/(?<![\w>$:\\\\])(?:app|resolve)\(/', $source) === 1) {
                $found[] = $this->relative($path);
            }
        }

        sort($found);

        $added = array_values(array_diff($found, self::DECLARED));
        $removed = array_values(array_diff(self::DECLARED, $found));

        $this->assertSame(self::DECLARED, $found, "Services resolving from the container changed.\n"
            .($added === [] ? '' : "Now resolving (inject it instead): \n  ".implode("\n  ", $added)."\n")
            .($removed === [] ? '' : "No longer resolving (delist): \n  ".implode("\n  ", $removed)."\n"));
    }

    /**
     * @return list<string>
     */
    private function services(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DOMAINS));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->isFile() && str_ends_with($path, '.php') && str_contains($path, '/Services/')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        $root = str_replace('\\', '/', (string) realpath(self::DOMAINS.'/..')).'/';

        return str_replace($root, '', str_replace('\\', '/', (string) realpath($path)));
    }
}
