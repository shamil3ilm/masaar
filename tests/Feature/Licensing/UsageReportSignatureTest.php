<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Services\UsageReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Usage reports are signed, so the licence server takes them only from a
 * deployment holding the licence secret.
 *
 * The server's /usage took any key and any figures from anyone, and those
 * figures feed billing. It now wants X-Usage-Timestamp and X-Usage-Signature:
 * hex HMAC-SHA256 under the licence secret of "<timestamp>.<exact body>"
 * (cloudflare-worker/license-server.js, usageSignatureValid()). This pins the
 * deployment's half of that format.
 */
class UsageReportSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-licence-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'platform-license.server_url' => 'https://licences.example.test',
            'platform-license.key' => 'ACME-PROD-20270101-abcd1234',
            'platform-license.signing_secret' => self::SECRET,
        ]);
    }

    public function test_report_is_signed_over_its_body(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);

        $this->assertTrue(app(UsageReportingService::class)->report()['success']);

        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Usage-Timestamp')[0] ?? '';
            $signature = $request->header('X-Usage-Signature')[0] ?? '';

            return $request->url() === 'https://licences.example.test/usage'
                && ctype_digit($timestamp)
                && abs(time() - (int) $timestamp) < 60
                && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET), $signature)
                && $request['license_key'] === 'ACME-PROD-20270101-abcd1234';
        });
    }

    public function test_no_secret_sends_nothing(): void
    {
        config(['platform-license.signing_secret' => null]);
        Http::fake();

        $this->assertFalse(app(UsageReportingService::class)->report()['success']);

        Http::assertNothingSent();
    }
}
