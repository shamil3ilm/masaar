<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Two things about nginx that are easy to write wrongly and impossible to see.
 *
 * First, regex locations are tried in the order they appear and the first
 * match wins - there is no "most specific" rule as there is for prefix
 * locations. So a `deny all` written below a broad regex never runs. In this
 * file the denies for /storage and /vendor sat below both `\.php$` and the
 * static-extension block, which meant /storage/x.php was handed to php-fpm
 * and /storage/x.svg was served, by the two rules written to prevent exactly
 * that. Nothing was exploitable, because public/storage does not exist until
 * somebody runs storage:link - which is the point. The guard would have been
 * silently absent on the day it started to matter.
 *
 * Second, add_header does not accumulate across levels. A location that sets
 * any add_header discards every add_header inherited from the server block.
 * The static-file location set Cache-Control, and so shipped every stylesheet,
 * script, font and image with none of the four security headers above it.
 * That is the warning the nightly scan reports against the dev server, which
 * serves public/ without Laravel and has the same hole for a different
 * reason.
 *
 * Neither is a mistake nginx reports. It starts, it serves, and the headers
 * are simply not there.
 */
class NginxPostureTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../../docker/nginx/default.conf';

    /**
     * A refusal has to be written above anything that would answer first.
     */
    public function test_denials_precede_the_regex_blocks(): void
    {
        $answering = [];
        $late = [];

        foreach ($this->locations() as $location) {
            if (! str_starts_with($location['match'], '~')) {
                continue;
            }

            if (! str_contains($location['body'], 'deny all')) {
                $answering[] = $location['match'];

                continue;
            }

            if ($answering !== []) {
                $late[] = 'line '.$location['line'].': location '.$location['match']
                    .' denies, but '.$answering[0].' is written above it and answers first';
            }
        }

        $this->assertSame([], $late, implode("\n", $late)."\n\n"
            .'nginx tries regex locations in order and takes the first match, so '
            .'a deny below a serving block is never reached. Move every deny '
            .'above them.');
    }

    /**
     * And a location that adds one header has to re-add the rest.
     *
     * The expected set is read out of the server block rather than listed
     * here, so adding a header at the top of the file makes this test demand
     * it in the locations too.
     */
    public function test_added_headers_replace_the_inherited(): void
    {
        $expected = $this->serverHeaders();

        $this->assertNotSame([], $expected, 'The server block sets no add_header at all.');

        $dropped = [];

        foreach ($this->locations() as $location) {
            if (! str_contains($location['body'], 'add_header')) {
                continue;
            }

            foreach ($expected as $header) {
                if (! str_contains($location['body'], $header)) {
                    $dropped[] = 'line '.$location['line'].': location '.$location['match']
                        .' sets add_header, so it serves without '.$header;
                }
            }
        }

        $this->assertSame([], $dropped, implode("\n", $dropped)."\n\n"
            .'add_header replaces the inherited set rather than adding to it. A '
            .'location that sets any header must set all of them.');
    }

    /**
     * The header names added in the server block itself.
     *
     * @return list<string>
     */
    private function serverHeaders(): array
    {
        $names = [];
        $depth = 0;

        foreach ($this->lines() as $line) {
            $trimmed = trim($line);

            if ($depth === 1 && preg_match('/^add_header\s+(\S+)/', $trimmed, $m) === 1) {
                $names[] = $m[1];
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $names;
    }

    /**
     * Every location block, in the order written, with its body.
     *
     * @return list<array{match: string, line: int, body: string}>
     */
    private function locations(): array
    {
        $locations = [];
        $depth = 0;
        $open = null;
        $body = '';

        foreach ($this->lines() as $index => $line) {
            if ($open === null && $depth === 1
                && preg_match('/^\s*location\s+(.+?)\s*\{/', $line, $m) === 1) {
                $open = ['match' => trim($m[1]), 'line' => $index + 1];
                $body = '';
            } elseif ($open !== null) {
                $body .= $line."\n";
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            if ($open !== null && $depth === 1) {
                $locations[] = $open + ['body' => $body];
                $open = null;
            }
        }

        return $locations;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $contents = file_get_contents(self::CONFIG);

        self::assertNotFalse($contents, 'docker/nginx/default.conf is missing.');

        return explode("\n", $contents);
    }
}
