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
     *
     * Held as SHA-256 of each lowercased name rather than as the names: a
     * pattern spelling them out would be the one place left that published
     * them. Matched against every word of a line.
     */
    private const PARTNER_NAMES = [
        '1307868534e100e7b2295fcca50a234f448bceec33c27d3270a31bade303af0b',
    ];

    /**
     * Names and places that only make sense inside the project.
     */
    private const INTERNAL = '/shamil3ilm\.workers\.dev|docs\/superpowers|docs\/audit\/|_audit\/|erp-backend|erp-frontend|bounty/i';

    /**
     * Hosts and addresses that were invented for the project and serve
     * nothing: a reader who wrote to support@ the product's name .sa, or
     * pointed a client at api. the same, reached nobody. Examples use
     * example.com. Held as SHA-256 of the lowercased name, as the partners
     * are, so this file does not spell the product's name (BrandNameTest).
     */
    private const INVENTED_HOSTS = [
        '5390b0c25867e3d1cd6c189c40389840329fcdad0f4e080d75b36ed0618d7a29',
    ];

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
                if (preg_match(self::DATED, $line) || $this->internal($line) || $this->namesPartner($line)) {
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
                if ($this->namesPartner($line) || $this->internal($line)) {
                    $offending[] = $this->relative($file).':'.($number + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offending, "Documentation names a partner or an internal resource:\n  ".implode("\n  ", $offending));
    }

    /**
     * The hashed check has to fire on a real name; a typo in a hash would
     * otherwise pass every document forever. The probe is assembled so the
     * name never appears in this file whole.
     */
    public function test_the_partner_check_fires(): void
    {
        $this->assertTrue($this->namesPartner('ops@'.strrev('ylfxat').'.test'));
        $this->assertFalse($this->namesPartner('api.example.com'));
    }

    /** An internal reference, or a host invented for the project. */
    private function internal(string $line): bool
    {
        if (preg_match(self::INTERNAL, $line)) {
            return true;
        }

        preg_match_all('/\b([a-z0-9-]+)\.(?:sa|io|com)\b|github\.com\/([a-z0-9-]+)\//i', strtolower($line), $hosts, PREG_SET_ORDER);

        foreach ($hosts as $host) {
            $name = ($host[2] ?? '') !== '' ? $host[2] : $host[1];

            if (in_array(hash('sha256', $name), self::INVENTED_HOSTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function namesPartner(string $line): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', strtolower($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array(hash('sha256', $word), self::PARTNER_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function readmes(): array
    {
        return array_merge(
            glob(self::ROOT.'/*.md') ?: [],
            glob(self::ROOT.'/docs/*/README.md') ?: [],
            glob(self::ROOT.'/docs/*/*/README.md') ?: [],
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
            glob(self::ROOT.'/docs/*/*/*.md') ?: [],
        );
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace(str_replace('\\', '/', (string) realpath(self::ROOT)), '', str_replace('\\', '/', (string) realpath($file))), '/');
    }
}
