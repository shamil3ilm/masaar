<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domains\Webhook\Services\WebhookService;
use Tests\TestCase;

/**
 * docs/integration/WEBHOOKS.md lists every event a webhook can subscribe to,
 * and its PHP verification accepts what the platform signs - with either
 * secret during a rotation - and refuses a changed body or a stale one.
 */
class WebhookDocsTest extends TestCase
{
    private const PAGE = 'docs/integration/WEBHOOKS.md';

    public function test_every_event_is_documented(): void
    {
        $page = (string) file_get_contents(base_path(self::PAGE));

        foreach ([...WebhookService::EVENTS, 'webhook.test'] as $event) {
            $this->assertStringContainsString('`'.$event.'`', $page, $event);
        }
    }

    public function test_the_php_sample_verifies(): void
    {
        $page = (string) file_get_contents(base_path(self::PAGE));
        $this->assertSame(1, preg_match('/```php\R(.*?)^```/ms', $page, $match));

        $secret = str_repeat('k', 64);
        $body = ['id' => 'delivery-1', 'event' => 'invoice.cleared', 'timestamp' => now()->toISOString(), 'data' => ['invoice_number' => 'INV-1/1']];
        $signature = app(WebhookService::class)->generateSignature($body, $secret);

        $this->assertTrue($this->verify($match[1], (string) json_encode($body), $signature, $secret));
        $this->assertFalse($this->verify($match[1], (string) json_encode([...$body, 'event' => 'invoice.rejected']), $signature, $secret));

        // During a rotation the header carries a signature per secret, and
        // either one verifies.
        $other = app(WebhookService::class)->generateSignature($body, str_repeat('o', 64));
        $this->assertTrue($this->verify($match[1], (string) json_encode($body), $other.','.$signature, $secret));

        // A delivery signed long ago and sent again is refused.
        $stale = [...$body, 'timestamp' => now()->subMinutes(10)->toISOString()];
        $this->assertFalse($this->verify($match[1], (string) json_encode($stale), app(WebhookService::class)->generateSignature($stale, $secret), $secret));
    }

    private function verify(string $sample, string $raw, string $signature, string $secret): bool
    {
        $request = new class($raw, $signature)
        {
            public function __construct(private string $raw, private string $signature) {}

            public function getContent(): string
            {
                return $this->raw;
            }

            public function header(string $name): string
            {
                return $this->signature;
            }
        };

        return (function () use ($sample, $request, $secret): bool {
            eval($sample);

            return $valid;
        })();
    }
}
