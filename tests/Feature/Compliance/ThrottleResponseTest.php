<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Being rate limited by the authority is not being refused by it.
 *
 * Every unsuccessful response from ZATCA became a generic failure carrying a
 * status code in a string, and SubmissionLedger turns any unsuccessful
 * response into 'rejected'. So a device ZATCA was throttling was recorded in
 * exactly the state that means the authority examined the document and would
 * not accept it - the one field an operator reads to decide whether to fix the
 * invoice or wait. ErrorCode::ZATCA_RATE_LIMITED existed for this, marked
 * retryable with a delay, and was never assigned to anything.
 *
 * This matters most where it is least visible: a simplified invoice has
 * twenty-four hours to be reported, and an operator told it was rejected will
 * go looking for a fault in a document that has none.
 */
class ThrottleResponseTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: array<string, string>, 2: int}>
     */
    public static function throttles(): array
    {
        return [
            '429 with seconds' => [429, ['Retry-After' => '90'], 90],
            '429 without a hint' => [429, [], 60],
            '503 shedding load' => [503, ['Retry-After' => '15'], 15],
            '429 with an unreadable hint' => [429, ['Retry-After' => 'soon'], 60],
        ];
    }

    /**
     * The wait is the authority's figure where it gave one, so the platform
     * does not need to know ZATCA's rate limit to respect it.
     *
     * @param  array<string, string>  $headers
     */
    #[DataProvider('throttles')]
    public function test_a_throttle_is_read_as_one(int $status, array $headers, int $expected): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'too many'], $status, $headers),
        ]);

        $response = app(FatooraClient::class)->reportInvoice('<Invoice/>', 'hash', 'uuid');

        $this->assertFalse($response->success);
        $this->assertSame(ErrorCode::ZATCA_RATE_LIMITED, $response->errorCode);
        $this->assertSame($expected, $response->retryAfterSeconds);
        $this->assertTrue($response->isRetryable(), 'A throttle has to be retryable.');
    }

    /**
     * And a genuine refusal still is one, or the distinction would be useless
     * in the other direction.
     */
    public function test_a_refusal_is_not_retryable(): void
    {
        Http::fake([
            '*' => Http::response(['errorMessages' => [['code' => 'BR-KSA-01']]], 400),
        ]);

        $response = app(FatooraClient::class)->reportInvoice('<Invoice/>', 'hash', 'uuid');

        $this->assertFalse($response->success);
        $this->assertNull($response->errorCode);
        $this->assertFalse($response->isRetryable(), 'A refused document must not be retried as a throttle.');
    }

    /**
     * An HTTP date is the other form the specification allows, and a gateway
     * often phrases it that way.
     */
    public function test_a_dated_hint_is_honoured(): void
    {
        Http::fake([
            '*' => Http::response([], 429, [
                'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120),
            ]),
        ]);

        $response = app(FatooraClient::class)->reportInvoice('<Invoice/>', 'hash', 'uuid');

        // Allowing for the second that may pass while the request is made.
        $this->assertGreaterThan(100, (int) $response->retryAfterSeconds);
        $this->assertLessThanOrEqual(120, (int) $response->retryAfterSeconds);
    }

    /**
     * A response built by hand carries no code, so nothing that existed
     * before this became retryable by accident.
     */
    public function test_an_ordinary_failure_carries_no_code(): void
    {
        $response = FatooraResponse::failed('something went wrong');

        $this->assertNull($response->errorCode);
        $this->assertNull($response->retryAfterSeconds);
        $this->assertFalse($response->isRetryable());
    }
}
