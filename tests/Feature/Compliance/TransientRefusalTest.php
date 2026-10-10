<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The authority faltering is not the authority refusing a document.
 *
 * `SubmissionLedger` already distinguishes the two, and says why in as many
 * words: a retryable failure is recorded as 'failed', which is re-sendable,
 * because 'rejected' asserts the document was wrong. Only the predicate
 * feeding it was incomplete - `throttle()` recognised 429 and 503, and every
 * other unsuccessful status arrived with no error code at all. `isRetryable()`
 * then answered false and a 500 from ZATCA was recorded as a rejection.
 *
 * That matters most for the documents with the least slack. A simplified
 * invoice is reported within twenty-four hours; spending that window on a
 * gateway blip, having told the operator the invoice was bad, is the outcome
 * this distinction exists to prevent.
 *
 * It is the same omission ZATCA_RATE_LIMITED had before it was fixed: the
 * enum cases existed, were already listed as retryable, and were assigned by
 * nothing. So these assert the assignment, not the classification - the enum
 * was never the part that was wrong.
 */
class TransientRefusalTest extends TestCase
{
    /**
     * Statuses that say nothing about the document, and the code each takes.
     *
     * 503 is absent deliberately: it is claimed first as throttling, with the
     * authority's own Retry-After, and `ThrottleResponseTest` covers it.
     *
     * @return list<array{0: int, 1: ErrorCode}>
     */
    public static function faltering(): array
    {
        return [
            [408, ErrorCode::ZATCA_TIMEOUT],
            [504, ErrorCode::ZATCA_TIMEOUT],
            [500, ErrorCode::ZATCA_SERVICE_UNAVAILABLE],
            [502, ErrorCode::ZATCA_SERVICE_UNAVAILABLE],
        ];
    }

    #[DataProvider('faltering')]
    public function test_a_faltering_gateway_is_retryable(int $status, ErrorCode $expected): void
    {
        Http::fake(['*' => Http::response('gateway error', $status)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid');

        $this->assertFalse($response->success, 'A failing status is still a failure.');

        $this->assertSame(
            $expected,
            $response->errorCode,
            "HTTP {$status} is the authority faltering and carried no error code, "
            .'so the submission would be recorded as rejected - which says the '
            .'document was wrong.'
        );

        $this->assertTrue(
            $response->isRetryable(),
            "HTTP {$status} must be re-sendable. The document was never looked at."
        );
    }

    /**
     * And a status that *is* about the document stays un-retryable, so this
     * did not simply make every failure retryable - which would send a
     * document the authority has already refused, over and over.
     *
     * @return list<array{0: int}>
     */
    public static function aboutTheDocument(): array
    {
        return [[400], [401], [413]];
    }

    #[DataProvider('aboutTheDocument')]
    public function test_a_refusal_is_not_retryable(int $status): void
    {
        Http::fake(['*' => Http::response(['errorMessages' => [['message' => 'PIH is inValid']]], $status)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid');

        $this->assertNull($response->errorCode, "HTTP {$status} says something about the document.");
        $this->assertFalse($response->isRetryable(), "HTTP {$status} must not be resent unchanged.");
    }

    /**
     * A refusal names the rule it broke, and that is what the operator reads.
     *
     * The reasons were left in the raw body behind 'ZATCA API returned status:
     * 400', where they are found only by someone who already suspects they
     * are there.
     */
    public function test_a_refusal_carries_the_authority_reasons(): void
    {
        Http::fake(['*' => Http::response([
            'errorMessages' => [
                ['code' => 'BR-KSA-13', 'message' => 'PIH is inValid'],
                ['code' => 'BR-KSA-27', 'message' => 'QR code is missing'],
            ],
        ], 400)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid');

        $this->assertStringContainsString('PIH is inValid', implode(' ', $response->errorMessages));
        $this->assertStringContainsString('QR code is missing', implode(' ', $response->errorMessages));
    }

    /**
     * Reasons also arrive nested under validationResults, which is where a
     * clearance refusal puts them.
     */
    public function test_nested_validation_reasons_are_read(): void
    {
        Http::fake(['*' => Http::response([
            'validationResults' => [
                'status' => 'ERROR',
                'errorMessages' => [['code' => 'BR-KSA-02', 'message' => 'Invoice type code is invalid']],
            ],
        ], 400)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid');

        $this->assertStringContainsString(
            'Invoice type code is invalid',
            implode(' ', $response->errorMessages)
        );
    }

    /**
     * And a body with nothing readable in it still says what happened, rather
     * than reporting an empty reason.
     */
    public function test_an_opaque_body_names_the_status(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid');

        $this->assertStringContainsString('502', implode(' ', $response->errorMessages));
    }
}
