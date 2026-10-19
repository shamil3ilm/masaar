<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The docs tell a receiver to read the header the platform sends.
 *
 * WebhookService signs into X-Webhook-Signature. The integration guide and
 * the client SDKs once kept here told receivers to read an X-<product>-Signature header,
 * which never arrives, so a receiver built from them read an empty header and either
 * refused every webhook or - worse - skipped verification. The guide's own
 * comparison also left off the sha256= prefix the header carries.
 */
class WebhookHeaderDocsTest extends TestCase
{
    private const SENT = 'X-Webhook-Signature';

    private const ROOT = __DIR__.'/../../..';

    public function test_docs_name_the_sent_header(): void
    {
        $this->assertStringContainsString(
            "'".self::SENT."'",
            (string) file_get_contents(self::ROOT.'/app/Domains/Webhook/Services/WebhookService.php'),
            'WebhookService no longer sends '.self::SENT.'; update this test and every doc it guards.'
        );

        $wrong = [];

        foreach ($this->documents() as $path) {
            preg_match_all('/X-[A-Za-z]+-Signature/', (string) file_get_contents($path), $matches);

            foreach (array_unique($matches[0]) as $header) {
                if ($header !== self::SENT) {
                    $wrong[] = str_replace(self::ROOT.'/', '', $path).': '.$header;
                }
            }
        }

        $this->assertSame([], $wrong, 'These documents name a signature header the platform does not send.');
    }

    /** @return list<string> */
    private function documents(): array
    {
        return array_merge(glob(self::ROOT.'/docs/*.md') ?: [], [self::ROOT.'/README.md']);
    }
}
