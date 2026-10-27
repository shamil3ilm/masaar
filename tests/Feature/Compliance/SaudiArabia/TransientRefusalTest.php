<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\FatooraClient;
use App\Support\Errors\ErrorCode;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * Whether each status may have delivered the document. ZATCA's table
     * (Detailed Guidelines, "Overview of responses and required actions")
     * says 500, 503 and 504 are "invoice not received - please resend"; a
     * status it does not list, such as a 502 from a gateway in front of it,
     * says nothing either way and is held.
     *
     * @return list<array{0: int, 1: bool}>
     */
    public static function arrival(): array
    {
        return [[408, false], [500, false], [504, false], [502, true]];
    }

    #[DataProvider('arrival')]
    public function test_arrival_follows_the_response_table(int $status, bool $mayHaveArrived): void
    {
        Http::fake(['*' => Http::response(['message' => 'gateway error'], $status)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertTrue($response->isRetryable());
        $this->assertSame($mayHaveArrived, $response->mayHaveArrived);
    }

    #[DataProvider('faltering')]
    public function test_a_faltering_gateway_is_retryable(int $status, ErrorCode $expected): void
    {
        Http::fake(['*' => Http::response('gateway error', $status)]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

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
     * No answer at all is the plainest case of the authority not looking.
     *
     * A refused connection, a DNS failure or a cURL timeout never reaches a
     * status, so the classification above never saw it: the client caught the
     * exception and returned a failure with no code, and the ledger recorded
     * an outage as a rejection.
     */
    public function test_an_unreachable_authority_is_retryable(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to gw-fatoora.zatca.gov.sa')]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertFalse($response->success);
        $this->assertSame(ErrorCode::ZATCA_SERVICE_UNAVAILABLE, $response->errorCode);
        $this->assertTrue($response->isRetryable(), 'Nothing reached the authority, so resending is safe.');
        $this->assertFalse($response->mayHaveArrived);
    }

    /**
     * A read that timed out is retryable too, but it is not "nothing reached
     * the authority": the request went out, and a clearance ZATCA granted
     * before the timeout would be filed twice by sending it again. This test
     * used to say otherwise.
     */
    public function test_a_read_timeout_may_have_arrived(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')]);

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertSame(ErrorCode::ZATCA_TIMEOUT, $response->errorCode);
        $this->assertTrue($response->isRetryable());
        $this->assertTrue($response->mayHaveArrived);
    }

    /**
     * And a status that is not the authority faltering stays un-retryable,
     * so this did not simply make every failure retryable - which would send
     * the same request over and over. Only the 400 judged the document; a
     * 401 or 413 is held, not rejected (NonVerdictAnswersTest).
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

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertSame($status === 400, $response->errorCode === null, "HTTP {$status} is classified wrongly.");
        $this->assertSame($status === 400, $response->isVerdict(), "only HTTP 400 judges the document, not {$status}.");
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

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

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

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

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

        $response = app(FatooraClient::class)->checkCompliance('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        $this->assertStringContainsString('502', implode(' ', $response->errorMessages));
    }
}
