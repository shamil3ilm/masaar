<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A model attribute that is read must be answerable.
 *
 * Eloquent returns null for an attribute a model does not have, rather than
 * failing, and a guard written beside it then decides on nothing. This was the
 * most productive defect in the codebase:
 *
 *   $organization->zatca_certificate    documents went to ZATCA unsigned
 *   $invoice->previous_invoice_hash     every document claimed to be first in
 *                                       its chain
 *   $organization->is_suspended         a suspended tenant was never refused
 *   $invoice->exchange_rate             foreign currency was impossible
 *   $invoice->total_with_vat            every webhook reported a null amount
 *   $license->company_name              null in every response
 *
 * Every one of them had a `??` or an `isset()` beside it, so nothing failed and
 * nothing logged.
 *
 * The check is deliberately global rather than per-class: it collects every
 * snake_case property read and subtracts everything that could legitimately
 * answer one — any column in any migration, any accessor, relation, cast or
 * declared attribute on any model. A name that survives is answered by nothing
 * anywhere, which is a far stronger signal than trying to infer which class a
 * variable holds, and it does not need to be right about types to be right
 * about this.
 */
class AttributeReadTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_every_attribute_read_resolves(): void
    {
        $known = $this->answerableNames();
        $unresolved = [];

        foreach ($this->sourceFiles(self::ROOT.'/app') as $path) {
            foreach ($this->readsIn($path) as $name => $line) {
                if (! isset($known[$name])) {
                    $unresolved[] = sprintf('%s:%d reads ->%s', $this->relative($path), $line, $name);
                }
            }
        }

        sort($unresolved);

        $this->assertSame([], $unresolved, sprintf(
            'These attributes are read but nothing answers them — no column, accessor, '
            ."relation or cast anywhere.\nEloquent returns null and the guard beside it "
            ."decides on nothing:\n  %s",
            implode("\n  ", $unresolved)
        ));
    }

    /**
     * Everything that could legitimately answer a snake_case property read.
     *
     * @return array<string, true>
     */
    private function answerableNames(): array
    {
        $known = [];

        foreach (glob(self::ROOT.'/database/migrations/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            // ->string('name'), ->uuid('org_id'), ->foreignUuid('branch_id')
            preg_match_all('/->[a-zA-Z]+\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', $source, $matches);
            foreach ($matches[1] as $name) {
                $known[$name] = true;
            }
        }

        foreach ($this->sourceFiles(self::ROOT.'/app') as $path) {
            $source = (string) file_get_contents($path);

            // A column aliased in raw SQL answers a read on the stdClass a
            // query returns. Collected before comments and strings are stripped,
            // because that is where aliases live. The $ in `foreach ($x as $y)`
            // keeps loop variables out.
            preg_match_all('/\bas\s+([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/i', $source, $matches);
            foreach ($matches[1] as $name) {
                $known[strtolower($name)] = true;
            }

            // getFooBarAttribute() and the Laravel 9+ fooBar(): Attribute form.
            preg_match_all('/function\s+get([A-Za-z0-9]+)Attribute\s*\(/', $source, $matches);
            foreach ($matches[1] as $studly) {
                $known[$this->snake($studly)] = true;
            }

            preg_match_all('/function\s+([a-z][A-Za-z0-9]*)\s*\(\s*\)\s*:\s*Attribute/', $source, $matches);
            foreach ($matches[1] as $camel) {
                $known[$this->snake($camel)] = true;
            }

            preg_match_all(
                '/function\s+([a-z][A-Za-z0-9]*)\s*\(\s*\)\s*:\s*(?:HasMany|HasOne|BelongsTo|BelongsToMany|MorphTo|MorphMany|MorphOne|HasManyThrough|HasOneThrough)/',
                $source,
                $matches
            );
            foreach ($matches[1] as $camel) {
                $known[$this->snake($camel)] = true;
            }

            // Anything the model declares about itself: casts, appends,
            // fillable, hidden, dates.
            foreach (['casts', 'appends', 'fillable', 'hidden', 'dates'] as $property) {
                if (preg_match('/\$'.$property.'\s*=\s*\[(.*?)\];/s', $source, $block) === 1
                    || preg_match('/function\s+'.$property.'\s*\(\s*\)\s*:\s*array\s*\{\s*return\s*\[(.*?)\];/s', $source, $block) === 1) {
                    preg_match_all('/[\'"]([a-z][a-z0-9_]*)[\'"]/', $block[1], $keys);
                    foreach ($keys[1] as $name) {
                        $known[$name] = true;
                    }
                }
            }
        }

        return $known;
    }

    /**
     * Snake_case property reads in one file, excluding what is not a model.
     *
     * @return array<string, int> name => first line it appears on
     */
    private function readsIn(string $path): array
    {
        $source = (string) file_get_contents($path);

        // A form request answers $this->field from the request body, so every
        // input name would otherwise look unresolved.
        $isFormRequest = str_contains($source, 'extends FormRequest')
            || str_contains($source, 'extends CreateInvoiceRequest');

        $reads = [];

        foreach (explode("\n", $this->codeOnly($source)) as $index => $line) {
            // $request->field and $this->field inside a form request are both
            // request input, not model attributes.
            $line = preg_replace('/\$request->[a-z][a-z0-9_]*/', '', $line);

            if ($isFormRequest) {
                $line = preg_replace('/\$this->[a-z][a-z0-9_]*/', '', $line);
            }

            if (preg_match_all('/->([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b(?!\s*\()/', (string) $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $reads[$name] ??= $index + 1;
                }
            }
        }

        return $reads;
    }

    /**
     * The source with comments and string contents blanked out, line count
     * preserved. A name inside a comment is documentation, and a name inside a
     * string is usually SQL.
     */
    private function codeOnly(string $source): string
    {
        static $text = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_INLINE_HTML,
        ];

        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $out .= $token;

                continue;
            }

            $out .= in_array($token[0], $text, true)
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : $token[1];
        }

        return $out;
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $position = strrpos($path, '/app/');

        return $position === false ? $path : substr($path, $position + 1);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
