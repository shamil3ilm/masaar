<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class a file names must actually resolve.
 *
 * Moving a class into a new namespace silently breaks references that relied
 * on the two being co-located, because those carry no `use` statement for a
 * rewriter to find and no error until the line runs. AuditService lost its
 * AuditLog that way, and two Licensing models lost User, whose relations then
 * pointed at a class that does not exist.
 *
 * Names that only ever appear inside strings — ASN.1 structure names, for
 * instance — are listed as known text rather than treated as references.
 */
class ClassReferenceTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Names that appear in string literals or documentation, not as code.
     */
    private const NOT_CODE = [
        'Certificate',      // ASN.1 structure name in an OpenSSL template
        'TimeStampReq',     // RFC 3161 ASN.1 structures, referenced as text
        'MessageImprint',
        'TimeStampResp',
        'PKIStatusInfo',
        'PKIStatus',
        'Invoice',          // usage example inside the BelongsToTenant docblock
    ];

    /**
     * PHP built-ins and Laravel facade aliases, which need no import.
     */
    private const GLOBALS = [
        'self', 'static', 'parent', 'Closure', 'Exception', 'Throwable', 'Error', 'TypeError',
        'DateTime', 'DateTimeImmutable', 'ArrayObject', 'Generator', 'Countable',
        'ReflectionClass', 'ReflectionMethod', 'ReflectionProperty',
        'InvalidArgumentException', 'RuntimeException', 'LogicException', 'JsonException',
        'DOMDocument', 'DOMXPath', 'DOMElement',
        'Log', 'Str', 'DB', 'Cache', 'Schema', 'Artisan', 'Auth', 'Route', 'Hash',
        'Storage', 'Http', 'Redis', 'Validator', 'File', 'Config', 'Event', 'Queue',
        'Mail', 'Notification',
    ];

    public function test_all_class_references_resolve(): void
    {
        $unresolved = [];

        foreach ($this->phpFiles() as $path) {
            foreach ($this->unresolvedIn($path) as $name) {
                $unresolved[] = basename($path).' -> '.$name;
            }
        }

        $this->assertSame([], $unresolved, sprintf(
            'These class references do not resolve. A class was most likely moved to a new '
            ."namespace while a same-namespace reference to it was left behind:\n  %s",
            implode("\n  ", $unresolved)
        ));
    }

    /**
     * @return list<string>
     */
    private function unresolvedIn(string $path): array
    {
        $code = file_get_contents($path);

        preg_match('/^namespace\s+([^;]+);/m', $code, $m);
        $namespace = $m[1] ?? '';

        $known = array_flip(array_merge(self::GLOBALS, self::NOT_CODE));

        preg_match_all('/^use\s+(?:function\s+)?([^;]+);/m', $code, $uses);
        foreach ($uses[1] as $use) {
            $use = trim($use);
            $short = str_contains($use, ' as ')
                ? trim(explode(' as ', $use)[1])
                : substr(strrchr("\\$use", '\\'), 1);
            $known[$short] = true;
        }

        preg_match_all(
            '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
            $code,
            $declared
        );
        foreach ($declared[1] as $name) {
            $known[$name] = true;
        }

        preg_match_all('/(?<![\\\\$>\w])([A-Z]\w+)\s*::/', $code, $static);
        preg_match_all('/new\s+([A-Z]\w+)\s*\(/', $code, $instantiated);

        // Parent classes and interfaces matter most: PipelineSubmitRequest
        // extended a CreateInvoiceRequest that had moved to another domain,
        // which is a fatal error the moment the class is loaded.
        preg_match_all('/\b(?:extends|implements)\s+([A-Z][\w,\s]*)/', $code, $inherited);
        $parents = [];
        foreach ($inherited[1] as $clause) {
            foreach (explode(',', $clause) as $parent) {
                $parent = trim($parent);
                if ($parent !== '' && preg_match('/^[A-Z]\w*$/', $parent)) {
                    $parents[] = $parent;
                }
            }
        }

        $unresolved = [];

        foreach (array_unique([...$static[1], ...$instantiated[1], ...$parents]) as $name) {
            if (isset($known[$name]) || $this->resolves($name, $namespace)) {
                continue;
            }

            $unresolved[] = $name;
        }

        return $unresolved;
    }

    private function resolves(string $name, string $namespace): bool
    {
        foreach ([$namespace === '' ? $name : "$namespace\\$name", $name] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)
                || trait_exists($candidate) || enum_exists($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
