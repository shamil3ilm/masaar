<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Documentation may only point at files that exist.
 *
 * The security audit cited `app/Domains/Auth/Models/ApiKey.php` in two
 * findings after the class had been deleted, and `TenantIsolationGuard` in a
 * third after the remediation it recommended was carried out. A reader
 * following either link found nothing and had no way to tell whether the
 * finding was stale or the file merely moved.
 *
 * A document describing code is only as good as its correspondence to the
 * code, and that correspondence is exactly the sort of thing that rots
 * silently while every test stays green.
 *
 * Anchors are ignored: line numbers drift with every edit, and pinning them
 * would fail on changes that are not mistakes.
 */
class DocLinkTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * @return list<string>
     */
    private function docs(): array
    {
        return array_merge(
            glob(self::ROOT.'/docs/audit/*.md') ?: [],
            glob(self::ROOT.'/*.md') ?: [],
        );
    }

    public function test_every_linked_file_exists(): void
    {
        $broken = [];

        foreach ($this->docs() as $doc) {
            $body = (string) file_get_contents($doc);

            preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $body, $matches);

            foreach ($matches[1] as $link) {
                if (str_starts_with($link, 'http') || str_starts_with($link, 'mailto:')) {
                    continue;
                }

                if (! file_exists(dirname($doc).'/'.$link)) {
                    $broken[] = basename($doc).' -> '.$link;
                }
            }
        }

        $broken = array_values(array_unique($broken));
        sort($broken);

        $this->assertSame([], $broken, sprintf(
            "These documents link to files that do not exist:\n  %s",
            implode("\n  ", $broken)
        ));
    }
}
