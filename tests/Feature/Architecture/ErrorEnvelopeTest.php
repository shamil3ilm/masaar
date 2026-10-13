<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Http\Responses\ApiResponse;
use Tests\TestCase;

/**
 * Every API error leaves by the same door, in the same shape.
 *
 * bootstrap/app.php had eight render handlers and four of them built the
 * response body by hand, so the envelope was really five shapes that
 * disagreed about which keys exist:
 *
 *   `category` was present on four of the eight.
 *   `retryable` was present on exactly one - the FatooraException handler.
 *   Extra detail arrived as `error.details` on one path and `error.context`
 *   on two.
 *
 * The `retryable` asymmetry is the one that cost something. A client written
 * against the documented envelope - retry when the error says it is
 * retryable - never retried anything that arrived by the other seven paths,
 * because the key was absent and absent reads as false. Every 503 rendered
 * from an HttpException, and every upstream timeout that reached the Throwable
 * handler, was treated by that client as permanent.
 *
 * So `retryable` is now derived from the status inside the one builder rather
 * than passed in by each handler. That is the fix: a handler cannot forget a
 * key it does not supply.
 */
class ErrorEnvelopeTest extends TestCase
{
    /**
     * The keys every error body carries, and no others by default.
     */
    public function test_every_error_has_one_shape(): void
    {
        $body = ApiResponse::error('No', 404)->getData(true);

        $this->assertSame(['success', 'error'], array_keys($body));
        $this->assertFalse($body['success']);
        $this->assertSame(
            ['code', 'message', 'category', 'retryable'],
            array_keys($body['error']),
            'the error block gained or lost a key'
        );
    }

    /**
     * Retryability is read off the status, so no handler can omit it.
     *
     * 500 is excluded on purpose: it is a defect on this side, and repeating
     * the request achieves nothing but load.
     */
    public function test_retryable_follows_the_status(): void
    {
        foreach ([408, 429, 502, 503, 504] as $status) {
            $this->assertTrue(
                ApiResponse::error('x', $status)->getData(true)['error']['retryable'],
                $status.' invites a retry and must say so'
            );
        }

        foreach ([400, 401, 403, 404, 409, 422, 500] as $status) {
            $this->assertFalse(
                ApiResponse::error('x', $status)->getData(true)['error']['retryable'],
                $status.' must not invite a retry'
            );
        }
    }

    /**
     * Every status a handler can produce names itself.
     *
     * The old map stopped at 500 and sent the rest to 'ERROR', so a 503
     * rendered from an HttpException described itself as 'ERROR'.
     */
    public function test_no_status_answers_a_blank_code(): void
    {
        foreach ([400, 401, 403, 404, 405, 408, 409, 413, 422, 429, 500, 502, 503, 504] as $status) {
            $code = ApiResponse::error('x', $status)->getData(true)['error']['code'];

            $this->assertNotSame('ERROR', $code, $status.' has no code of its own');
            $this->assertNotSame('', $code);
        }
    }

    /**
     * A retryable error carries the advice, and the header with it.
     *
     * The licence handler was the only one that set Retry-After, and only for
     * one hardcoded error-code string.
     */
    public function test_a_retryable_error_sets_retry_after(): void
    {
        $response = ApiResponse::error('later', 503, [], null, null, 30, 5);
        $body = $response->getData(true);

        $this->assertSame(30, $body['error']['retry_after']);
        $this->assertSame(5, $body['error']['max_retries']);
        $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    /**
     * And a fatal one carries neither, rather than carrying nulls.
     */
    public function test_a_fatal_error_omits_retry_advice(): void
    {
        $body = ApiResponse::error('no', 422, [], null, null, 30, 5)->getData(true);

        $this->assertArrayNotHasKey('retry_after', $body['error']);
        $this->assertArrayNotHasKey('max_retries', $body['error']);
        $this->assertNull(ApiResponse::error('no', 422, [], null, null, 30)->headers->get('Retry-After'));
    }

    /**
     * A compliance failure keeps the taxonomy it was raised with.
     *
     * ComplianceController caught a FatooraException, read its ErrorCode to
     * decide whether to handle it, and then passed on getMessage() alone with
     * a hardcoded 422 - so the same ZATCA condition answered in two different
     * shapes depending on which branch of that decision fired.
     */
    public function test_a_fatoora_error_keeps_its_code(): void
    {
        $body = ApiResponse::fromFatoora(new FatooraException(
            'Invoice ACME-1 is issued and cannot be issued again.',
            ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
            ['invoice_number' => 'ACME-1'],
        ))->getData(true);

        $this->assertSame('VAL_025', $body['error']['code']);
        $this->assertSame('validation', $body['error']['category']);
        $this->assertFalse($body['error']['retryable']);
        $this->assertSame(['invoice_number' => 'ACME-1'], $body['error']['details']);
    }

    /**
     * The status comes off the code, so a caller cannot contradict it.
     */
    public function test_the_status_comes_off_the_code(): void
    {
        $this->assertSame(503, ApiResponse::fromFatoora(new FatooraException(
            'The authority is unavailable',
            ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
        ))->getStatusCode());

        $this->assertSame(422, ApiResponse::fromFatoora(new FatooraException(
            'Already issued',
            ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
        ))->getStatusCode());
    }

    /**
     * No handler builds an error body of its own any more.
     *
     * Read from the source, because the alternative is a feature test per
     * handler and the thing worth pinning is that there is one builder.
     */
    public function test_no_handler_builds_its_own_error(): void
    {
        $source = file_get_contents(__DIR__.'/../../../bootstrap/app.php');

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString(
            "'error' => [",
            $source,
            'a render handler is assembling its own error block instead of using ApiResponse'
        );
    }

    /**
     * No caller passes an HTTP status where the message belongs.
     *
     * BranchController::store() called success($data, 201). The 201 landed in
     * the ?string $message parameter and that file declares strict_types, so
     * it raised a TypeError - an Error, not an Exception, so the catch around
     * it never saw it. The request answered 500 with the branch already
     * written to the database, and no HTTP test covered the route.
     */
    public function test_no_status_in_the_message_slot(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(__DIR__.'/../../../app') as $file) {
            $source = (string) file_get_contents($file);

            foreach (['success', 'created'] as $method) {
                foreach ($this->argumentsOf($source, 'ApiResponse::'.$method.'(') as $arguments) {
                    if (isset($arguments[1]) && preg_match('/^\d+$/', trim($arguments[1])) === 1) {
                        $offenders[] = basename($file).' passes '.trim($arguments[1]).' to '.$method.'() as the message';
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * The top-level arguments of every call to $needle in $source.
     *
     * @return list<list<string>>
     */
    private function argumentsOf(string $source, string $needle): array
    {
        $calls = [];
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $i = $start + strlen($needle);
            $depth = 1;
            $argument = '';
            $arguments = [];

            for ($length = strlen($source); $i < $length; $i++) {
                $char = $source[$i];

                if (in_array($char, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($char, [')', ']', '}'], true)) {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $arguments[] = $argument;
                    $argument = '';

                    continue;
                }

                $argument .= $char;
            }

            if (trim($argument) !== '') {
                $arguments[] = $argument;
            }

            $calls[] = $arguments;
            $offset = $start + strlen($needle);
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
