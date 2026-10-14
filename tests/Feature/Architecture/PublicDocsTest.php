<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The repository is public, and its documents read that way.
 *
 * The READMEs had carried a live deployment URL, a partner by name, links
 * into an internal audit and design specs, and dates - release, guideline,
 * mandate and "last updated" - that went stale the day after they were
 * written. Authority timelines belong on the authorities' portals.
 */
class PublicDocsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * Partners and customers are not named anywhere in the documentation.
     */
    private const PARTNER_NAMES = '/example/i';

    /**
     * Names and places that only make sense inside the project.
     */
    private const INTERNAL = '/shamil3ilm\.workers\.dev|docs\/superpowers|docs\/audit\/|_audit\/|erp-backend|erp-frontend|bounty/i';

    /**
     * A calendar date, or a "last updated" line. Versions and legal citations
     * ("Decision No. 243 of 2025") carry a year but no month, so they pass.
     */
    private const DATED = '/\b(19|20)\d{2}-\d{2}(-\d{2})?\b|last updated|\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+(19|20)\d{2}\b|\b(19|20)\d{2}-\d{3}\b/i';

    public function test_readmes_carry_no_dates_or_internals(): void
    {
        $offending = [];

        foreach ($this->readmes() as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match(self::DATED, $line) || preg_match(self::INTERNAL, $line) || preg_match(self::PARTNER_NAMES, $line)) {
                    $offending[] = $this->relative($file).':'.($number + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offending, "README-type files must hold no dates, partner names or internal references:\n  ".implode("\n  ", $offending));
    }

    public function test_docs_name_no_partner(): void
    {
        $offending = [];

        foreach ($this->docs() as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (preg_match(self::PARTNER_NAMES, $line) || preg_match(self::INTERNAL, $line)) {
                    $offending[] = $this->relative($file).':'.($number + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offending, "Documentation names a partner or an internal resource:\n  ".implode("\n  ", $offending));
    }

    /**
     * @return list<string>
     */
    private function readmes(): array
    {
        return array_merge(
            glob(self::ROOT.'/*.md') ?: [],
            glob(self::ROOT.'/docs/*/README.md') ?: [],
            glob(self::ROOT.'/sdks/*/README.md') ?: [],
            glob(self::ROOT.'/cloudflare-worker/README.md') ?: [],
            glob(self::ROOT.'/tests/*/README.md') ?: [],
        );
    }

    /**
     * @return list<string>
     */
    private function docs(): array
    {
        return array_merge(
            glob(self::ROOT.'/docs/*.md') ?: [],
            glob(self::ROOT.'/docs/*/*.md') ?: [],
        );
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(str_replace('\\', '/', (string) realpath(self::ROOT)), '', str_replace('\\', '/', (string) realpath($file))), '/');
    }
}
