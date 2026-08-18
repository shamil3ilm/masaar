<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * A capability that is written but never reached does not exist.
 *
 * Two ways that happens here, and both are silent.
 *
 * An optional constructor parameter is skipped by the container, so the
 * collaborator resolves to null and every `if ($this->thing)` around it stops
 * running. The guard is still in the file, so review sees a check that no
 * longer happens: branch-level signing, duplicate detection and VAT-period
 * validation were all disabled this way.
 *
 * A class with no references at all is the same failure one level up — the
 * subsystem is complete and nothing calls it.
 */
class WiredDependencyTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Directories whose classes are reached by name rather than by reference.
     *
     * Console commands are invoked by signature and auto-discovered;
     * ScheduledCommandTest checks those actually resolve.
     */
    private const REACHED_BY_NAME = ['/Console/'];

    /**
     * No service may take an optional collaborator.
     *
     * Nullable-with-default is how a dependency goes missing without anything
     * failing. If a collaborator is genuinely optional, the caller should pass
     * it explicitly rather than letting the container drop it.
     */
    public function test_services_take_no_optional_collaborators(): void
    {
        $offenders = [];

        foreach ($this->classesIn('/Services/') as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $offenders[] = sprintf(
                        '%s::__construct($%s) is optional, so the container leaves it null',
                        class_basename($class),
                        $parameter->getName()
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * Every class must be reachable from something.
     *
     * A class nothing names is either dead or a subsystem that was never wired
     * to its entry point. Both are worth knowing about; neither should sit
     * quietly in the tree.
     */
    public function test_no_class_is_unreferenced(): void
    {
        $sources = $this->sourceText();
        $unreferenced = [];

        foreach ($this->phpFiles() as $path) {
            $name = basename($path, '.php');

            if ($this->reachedByName($path)) {
                continue;
            }

            $mentions = 0;
            foreach ($sources as $file => $text) {
                if ($file !== $path && preg_match('/\b'.preg_quote($name, '/').'\b/', $text)) {
                    $mentions++;
                    break;
                }
            }

            if ($mentions === 0) {
                $unreferenced[] = substr($path, strlen(realpath(self::APP)) + 1);
            }
        }

        $this->assertSame([], $unreferenced, sprintf(
            'Nothing references these. Either they are dead, or a subsystem was built '
            ."and never connected to its entry point:\n  %s",
            implode("\n  ", $unreferenced)
        ));
    }

    private function reachedByName(string $path): bool
    {
        $normalised = str_replace('\\', '/', $path);

        foreach (self::REACHED_BY_NAME as $segment) {
            if (str_contains($normalised, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $segment): array
    {
        $classes = [];

        foreach ($this->phpFiles() as $path) {
            if (! str_contains(str_replace('\\', '/', $path), $segment)) {
                continue;
            }

            $body = (string) file_get_contents($path);
            preg_match('/^namespace\s+([^;]+);/m', $body, $ns);

            $class = ($ns[1] ?? '').'\\'.basename($path, '.php');

            if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Every file that could name a class: app code plus the wiring around it.
     *
     * @return array<string, string>
     */
    private function sourceText(): array
    {
        $text = [];

        foreach ($this->phpFiles() as $path) {
            $text[$path] = (string) file_get_contents($path);
        }

        foreach (['routes', 'config', 'bootstrap', 'database'] as $dir) {
            $base = realpath(self::APP.'/../'.$dir);

            if ($base === false) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $text[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath(self::APP))) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
