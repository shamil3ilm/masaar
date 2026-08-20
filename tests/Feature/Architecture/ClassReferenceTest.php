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
 * rewriter to find, and nothing fails until the line runs. A parent class or
 * a belongsTo target can sit broken for as long as nothing exercises it.
 *
 * Comments and string literals are stripped before anything is read as a
 * reference. ASN.1 structure names, docblock examples, and the class names
 * GenerateOpenapi greps controller source for are text rather than code, and
 * matching them produced a failure whose verdict depended on which other
 * tests had already run.
 */
class ClassReferenceTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private const APP = self::ROOT.'/app';

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
        $code = $this->codeOnly((string) file_get_contents($path));

        preg_match('/^namespace\s+([^;]+);/m', $code, $m);
        $namespace = $m[1] ?? '';

        $known = array_flip(self::GLOBALS);

        // An import makes its short name resolvable — but only if the class it
        // names exists. Trusting the statement is how bootstrap/app.php kept a
        // `use ...\ZatcaException;` for a class the Fatoora rename had removed:
        // every later mention of the short name was treated as resolved because
        // the import vouched for it.
        preg_match_all('/^use\s+(function|const)?\s*([^;]+);/m', $code, $uses, PREG_SET_ORDER);
        $missingImports = [];

        foreach ($uses as [, $kind, $use]) {
            $use = trim($use);
            $short = str_contains($use, ' as ')
                ? trim(explode(' as ', $use)[1])
                : substr(strrchr("\\$use", '\\'), 1);
            $known[$short] = true;

            // Function and constant imports are not classes, and a grouped
            // import is a different shape than this reads.
            if ($kind !== '' || str_contains($use, '{')) {
                continue;
            }

            $fqcn = trim(str_contains($use, ' as ') ? explode(' as ', $use)[0] : $use);

            if (! $this->exists($fqcn)) {
                $missingImports[] = $fqcn;
            }
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

        // Parameter type hints, closures included. bootstrap/app.php hinted an
        // exception renderer on a class the Fatoora rename had removed. Laravel
        // matches renderers by reflecting the parameter type rather than loading
        // it, so nothing threw — the renderer simply never matched, and every
        // compliance failure fell through to the generic handler instead of the
        // structured error carrying its code, category and retry hints.
        preg_match_all('/[(,]\s*\??([A-Z]\w+)\s+\$\w+/', $code, $hinted);

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

        $unresolved = $missingImports;

        foreach (array_unique([...$static[1], ...$instantiated[1], ...$parents, ...$hinted[1]]) as $name) {
            if (isset($known[$name]) || $this->resolves($name, $namespace)) {
                continue;
            }

            $unresolved[] = $name;
        }

        return $unresolved;
    }

    /**
     * The source with comments and string contents blanked out.
     *
     * Line breaks are kept so the ^namespace and ^use anchors still match,
     * and each removed token leaves whitespace of the same length so nothing
     * either side of it joins up into a name that was never written.
     */
    private function codeOnly(string $code): string
    {
        static $text = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
        ];

        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out .= $token;

                continue;
            }

            $out .= in_array($token[0], $text, true)
                ? str_repeat(' ', strlen($token[1] ?? '') - substr_count($token[1] ?? '', "\n"))
                    .str_repeat("\n", substr_count($token[1] ?? '', "\n"))
                : $token[1];
        }

        return $out;
    }

    /**
     * A constant or enum case named on a class must exist there.
     *
     * PHP resolves Foo::BAR at runtime and raises a fatal Error if it is not
     * defined, so a wrong name sits silently until the line executes.
     * OfflineFallback listed three ErrorCode cases that do not exist —
     * NET_CONNECTION_TIMEOUT, NET_CONNECTION_REFUSED, ZATCA_GATEWAY_TIMEOUT —
     * inside the method deciding whether a failure was a connectivity failure.
     * It therefore died the moment an exception reached it, so the offline
     * fallback never ran, and the only way to find out was to trigger an
     * outage.
     */
    public function test_all_constant_references_resolve(): void
    {
        $unresolved = [];

        foreach ($this->phpFiles() as $path) {
            $code = $this->codeOnly((string) file_get_contents($path));

            preg_match('/^namespace\s+([^;]+);/m', $code, $match);
            $namespace = $match[1] ?? '';

            $imports = $this->importsIn($code);

            // Foo::BAR, but not Foo::bar() or Foo::$bar or Foo::class.
            preg_match_all('/(?<![\\\\$>\w])([A-Z]\w+)::([A-Z][A-Z0-9_]*)\b(?!\s*\()/', $code, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $class, $constant]) {
                if ($constant === 'class') {
                    continue;
                }

                $resolved = $imports[$class]
                    ?? ($namespace === '' ? $class : "$namespace\\$class");

                if (! class_exists($resolved) && ! enum_exists($resolved) && ! interface_exists($resolved)) {
                    // Unresolvable classes are the other test's business.
                    continue;
                }

                if (! (new \ReflectionClass($resolved))->hasConstant($constant)) {
                    $unresolved[] = basename($path).' -> '.$class.'::'.$constant;
                }
            }
        }

        $this->assertSame([], $unresolved, sprintf(
            'These constants and enum cases do not exist on the class named. PHP raises '
            ."a fatal Error when the line runs:\n  %s",
            implode("\n  ", array_unique($unresolved))
        ));
    }

    /**
     * Short name => fully qualified, from the file's use statements.
     *
     * @return array<string, string>
     */
    private function importsIn(string $code): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $code, $matches);

        $imports = [];

        foreach ($matches[1] as $use) {
            $use = trim($use);

            if (str_contains($use, ' as ')) {
                [$fqcn, $alias] = array_map('trim', explode(' as ', $use));
                $imports[$alias] = $fqcn;

                continue;
            }

            $imports[substr(strrchr("\\$use", '\\'), 1)] = $use;
        }

        return $imports;
    }

    /**
     * Whether a fully qualified name names anything loadable.
     */
    private function exists(string $fqcn): bool
    {
        return class_exists($fqcn)
            || interface_exists($fqcn)
            || trait_exists($fqcn)
            || enum_exists($fqcn);
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
    /**
     * app/, plus the wiring files beside it.
     *
     * Scanning app/ alone missed a live defect: bootstrap/app.php type-hinted
     * an exception renderer on ZatcaException, which the Fatoora rename had
     * turned into FatooraException. Laravel matches renderers by reflecting the
     * closure's parameter type rather than loading the class, so nothing threw
     * — the renderer simply never matched, and every compliance failure fell
     * through to the generic handler instead of the structured error with its
     * code, category and retry hints.
     *
     * Wiring is exactly where this hides: it names classes from everywhere and
     * is executed once at boot, where a mistake is quiet rather than fatal.
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach ([self::APP, self::ROOT.'/bootstrap', self::ROOT.'/routes', self::ROOT.'/config'] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
