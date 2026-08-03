<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces the mechanical parts of docs/NAMING.md.
 *
 * Conventions decay when they live only in review comments. These assertions
 * are the parts a machine can check; the judgement calls stay in the document.
 */
class NamingConventionTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    public function test_middleware_have_no_filler_prefix(): void
    {
        $offenders = [];

        foreach ($this->classesIn('Http/Middleware') as $class => $path) {
            foreach (['Ensure', 'Restrict', 'Resolve'] as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    $offenders[] = "{$class} — drop the '{$prefix}' prefix; mirror the route alias instead";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_console_commands_have_no_command_suffix(): void
    {
        $offenders = [];

        foreach ($this->classesIn('Console') as $class => $path) {
            if (str_ends_with($class, 'Command')) {
                $offenders[] = "{$class} — drop the 'Command' suffix; it sits in a Console directory";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_controllers_end_in_controller(): void
    {
        $offenders = [];

        foreach ($this->classesIn('Http/Controllers') as $class => $path) {
            if (! str_ends_with($class, 'Controller')) {
                $offenders[] = "{$class} at {$path}";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_form_requests_end_in_request(): void
    {
        $offenders = [];

        foreach ($this->classesIn('Http/Requests') as $class => $path) {
            if (! str_ends_with($class, 'Request')) {
                $offenders[] = "{$class} at {$path}";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_exceptions_end_in_exception(): void
    {
        $offenders = [];

        foreach ($this->classesIn('Exceptions') as $class => $path) {
            if (! str_ends_with($class, 'Exception')) {
                $offenders[] = "{$class} at {$path}";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The layer-based directories were folded into the domains. Their return
     * would mean the codebase is running two organizing principles again.
     */
    public function test_layer_directories_are_not_reintroduced(): void
    {
        foreach (['Models', 'Services', 'Audits', 'DTOs', 'Jobs', 'Policies'] as $dir) {
            $this->assertDirectoryDoesNotExist(
                self::APP.'/'.$dir,
                "app/{$dir} is back. Domain code belongs under app/Domains/<Domain>/."
            );
        }
    }

    /**
     * Class names are case-insensitive in PHP, so a class whose name matches an
     * imported one — differing only in case — is a fatal redeclare. Renaming
     * JwtAuthenticate to JwtAuth hit exactly this against Tymon's JWTAuth.
     */
    public function test_no_class_collides_with_its_own_imports(): void
    {
        $offenders = [];

        foreach ($this->allPhpFiles() as $path) {
            $body = file_get_contents($path);
            $class = basename($path, '.php');

            preg_match_all('/^use\s+([^;]+);/m', $body, $imports);

            foreach ($imports[1] ?? [] as $import) {
                $imported = trim(substr(strrchr(" \\$import", '\\'), 1));

                if ($imported !== $class && strcasecmp($imported, $class) === 0) {
                    $offenders[] = "{$class} collides with imported {$import}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * @return array<string, string> class name => path
     */
    private function classesIn(string $segment): array
    {
        $found = [];

        foreach ($this->allPhpFiles() as $path) {
            if (str_contains(str_replace('\\', '/', $path), "/{$segment}/")) {
                $found[basename($path, '.php')] = $path;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function allPhpFiles(): array
    {
        $files = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
