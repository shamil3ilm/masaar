<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * The error taxonomy answers for every code it declares.
 *
 * ErrorCode documents itself as the "Formal API Error Taxonomy ...
 * standardized error codes for SDK consumers and API clients", and
 * FatooraException puts getHttpStatus() straight into the HTTP response. So
 * every arm of these match expressions is a published contract.
 *
 * Both of the two that matter ended in a default. getHttpStatus() explicitly
 * mapped 46 of 100 cases and sent the rest to 500; getMessage() mapped 40 and
 * answered the rest with the string "An error occurred". Forty-six codes hit
 * both, so nearly half the taxonomy resolved to a 500 carrying no information
 * - and eighteen of those were VAL_*, where the caller sent a bad payload.
 *
 * That inverts the contract in the expensive direction. A 500 tells a client
 * the fault is ours and the request is worth retrying, so an ERP posting an
 * invalid currency would retry a request that can never succeed, while the
 * response gave nobody a reason. It also pages whoever is on call for a
 * malformed payload.
 *
 * A default arm is why none of this was visible: it makes a missing mapping
 * indistinguishable from a deliberate one. These tests remove that hiding
 * place, so adding a case without deciding its status and its message fails
 * the build instead of shipping a blank 500.
 */
class ErrorTaxonomyTest extends TestCase
{
    private const ENUM = __DIR__.'/../../../app/Domains/Compliance/Fatoora/Enums/ErrorCode.php';

    /**
     * Statuses that promise the caller the attempt is worth repeating.
     *
     * @var list<int>
     */
    private const RETRY_STATUSES = [429, 503, 504];

    /**
     * Every case is named in getHttpStatus() rather than left to a default.
     *
     * Read from the source because a default arm is invisible at runtime: a
     * code that falls through returns 500 exactly as one mapped to 500 does,
     * which is how fifty-four of them stayed unnoticed.
     */
    public function test_every_code_is_mapped(): void
    {
        $mapped = $this->casesNamedIn('getHttpStatus');

        $missing = array_diff($this->allCaseNames(), $mapped);

        $this->assertSame([], array_values($missing), sprintf(
            "%d error codes are not named in getHttpStatus() and fall through to the default:\n  %s",
            count($missing),
            implode("\n  ", $missing)
        ));
    }

    /**
     * And every case says something a person can act on.
     *
     * Asserted at runtime rather than against the source: the failure mode
     * here is not an absent arm but a useless string, and an arm mapped
     * explicitly to "An error occurred" would be just as bad.
     */
    public function test_every_code_has_a_message(): void
    {
        $blank = [];

        foreach (ErrorCode::cases() as $case) {
            $message = $case->getMessage();

            if (trim($message) === '' || $message === 'An error occurred') {
                $blank[] = $case->name;
            }
        }

        $this->assertSame([], $blank, sprintf(
            "%d error codes carry no usable message:\n  %s",
            count($blank),
            implode("\n  ", $blank)
        ));
    }

    public function test_every_code_states_its_category(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $this->assertNotSame(
                'unknown',
                $case->getCategory(),
                $case->name.' has no category, so its value lost its prefix'
            );
        }
    }

    /**
     * A validation failure is the caller's to fix, so it answers 4xx.
     *
     * This is the inversion that mattered: eighteen VAL_* codes answered 500,
     * telling an ERP its own malformed invoice was a fault on our side.
     */
    public function test_validation_codes_answer_client_errors(): void
    {
        foreach ($this->casesWithPrefix('VAL_') as $case) {
            $status = $case->getHttpStatus();

            $this->assertGreaterThanOrEqual(400, $status, $case->name.' is a client error');
            $this->assertLessThan(500, $status, sprintf(
                '%s answers %d. A validation failure is the caller\'s to fix, and a 5xx invites a retry that cannot succeed.',
                $case->name,
                $status
            ));
        }
    }

    public function test_auth_codes_answer_client_errors(): void
    {
        foreach ($this->casesWithPrefix('AUTH_') as $case) {
            $this->assertContains($case->getHttpStatus(), [401, 403, 404], sprintf(
                '%s answers %d, which is not one of the statuses an unauthenticated or '
                .'unauthorized caller knows how to act on.',
                $case->name,
                $case->getHttpStatus()
            ));
        }
    }

    /**
     * Retryable and the status must agree, in both directions.
     *
     * getRetryDelay() and getMaxRetries() are published beside the status, so
     * a code that says "retry in 30s" while answering 400 tells the client two
     * contradictory things and the SDK has to pick one.
     */
    public function test_retryable_codes_avoid_client_errors(): void
    {
        foreach (ErrorCode::cases() as $case) {
            if (! $case->isRetryable()) {
                continue;
            }

            $status = $case->getHttpStatus();

            // 408, 409 and 429 are the client-side statuses that do mean
            // "try again" - a timeout, a transient conflict, a rate limit.
            $this->assertTrue(
                $status >= 500 || in_array($status, [408, 409, 429], true),
                sprintf('%s is retryable but answers %d, which tells the client not to.', $case->name, $status)
            );
        }
    }

    public function test_fatal_codes_avoid_retry_statuses(): void
    {
        foreach (ErrorCode::cases() as $case) {
            if ($case->isRetryable()) {
                continue;
            }

            $this->assertNotContains($case->getHttpStatus(), self::RETRY_STATUSES, sprintf(
                '%s answers %d, which asks the client to retry, but isRetryable() says it must not.',
                $case->name,
                $case->getHttpStatus()
            ));
        }
    }

    /**
     * Two cases sharing a value would be one code to every client.
     */
    public function test_code_values_are_unique(): void
    {
        $values = array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases());

        $duplicates = array_keys(array_filter(array_count_values($values), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'these values are declared more than once: '.implode(', ', $duplicates));
    }

    /**
     * @return list<string>
     */
    private function allCaseNames(): array
    {
        return array_map(static fn (ErrorCode $c): string => $c->name, ErrorCode::cases());
    }

    /**
     * @return list<ErrorCode>
     */
    private function casesWithPrefix(string $prefix): array
    {
        return array_values(array_filter(
            ErrorCode::cases(),
            static fn (ErrorCode $c): bool => str_starts_with($c->value, $prefix)
        ));
    }

    /**
     * The case names a method body mentions as `self::NAME`.
     *
     * @return list<string>
     */
    private function casesNamedIn(string $method): array
    {
        $source = file_get_contents(self::ENUM);
        $this->assertNotFalse($source, 'the enum source is unreadable');

        $start = strpos($source, 'public function '.$method.'(');
        $this->assertNotFalse($start, $method.' is not declared on ErrorCode');

        // To the next method declaration, or the end of the file for the last.
        $next = strpos($source, 'public function ', $start + 1);
        $body = substr($source, $start, $next === false ? null : $next - $start);

        preg_match_all('/self::([A-Z][A-Z_0-9]*)/', $body, $matches);

        return array_values(array_unique($matches[1]));
    }
}
