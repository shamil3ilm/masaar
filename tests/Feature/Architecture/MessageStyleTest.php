<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\Fixtures\ReadsSourceCalls;
use Tests\TestCase;

/**
 * One message per response, worded the way the rest of them are.
 *
 * Fourteen controller actions put their message inside the data payload while
 * the envelope has a field for exactly that. Mostly the envelope was then
 * empty, so a client reading response.message got nothing. WebhookController
 * managed both at once and they disagreed: the envelope carried created()'s
 * default 'Created successfully' and data.message said 'Webhook created. Save
 * the secret - it will not be shown again.' - two different answers to the
 * same question in one body.
 *
 * The wording rule here is not invented. It is what roughly 90% of the
 * existing strings already do, so these tests pin the majority and remove the
 * outliers rather than imposing a new style: a named subject and a past
 * participle, sentence case, no "successfully" - 'Branch updated', not
 * 'Branch updated successfully.' or 'Saved'.
 *
 * Periods split by surface, which is also what the codebase already did. A
 * flash message is UI prose and takes one. A single-clause API envelope
 * message does not; a multi-sentence one keeps its periods because it is
 * telling the caller to go and do something.
 */
class MessageStyleTest extends TestCase
{
    use ReadsSourceCalls;

    private const APP = __DIR__.'/../../../app';

    /**
     * The flash keys, as FlashKeyTest knows them, plus the login page's own.
     *
     * @var list<string>
     */
    private const FLASH_KEYS = ['success', 'error', 'warning', 'status'];

    /**
     * No response says one thing in the envelope and another in the payload.
     */
    public function test_no_response_carries_two_messages(): void
    {
        $offenders = [];

        foreach ($this->responseCalls(['success', 'created']) as [$file, $arguments]) {
            if (isset($arguments[0]) && str_contains($arguments[0], "'message' =>")) {
                $offenders[] = $file.' puts a message in the data payload; pass it as the message argument';
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * And none of them pads the verb.
     *
     * 'Invoice submitted' already says it succeeded - that is what a 200 and
     * success: true are for. The adverb was on 6 of 28 envelope messages,
     * which is how you end up with two houses of style.
     */
    public function test_no_message_says_successfully(): void
    {
        $offenders = [];

        foreach ($this->responseCalls(['success', 'created', 'error']) as [$file, $arguments]) {
            foreach ($arguments as $argument) {
                if (stripos($argument, 'successfully') !== false) {
                    $offenders[] = $file.': '.trim($argument);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * created() names what it created.
     *
     * Its message used to default to 'Created successfully', which is how
     * WebhookController came to answer with a message it had not written.
     * There is no default now, so the subject has to be named.
     */
    public function test_created_always_names_its_subject(): void
    {
        $offenders = [];

        foreach ($this->responseCalls(['created']) as [$file, $arguments]) {
            if (count($arguments) < 2) {
                $offenders[] = $file.' calls created() without a message';
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * A flash message is a sentence shown to a person, so it ends like one.
     *
     * Only literal strings are checked. The ones built by concatenating an
     * exception message cannot be judged here, and their own wording is the
     * exception's.
     */
    public function test_flash_messages_end_in_periods(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(self::APP) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->argumentsOf($source, '->with(') as $arguments) {
                if (count($arguments) < 2) {
                    continue;
                }

                $key = trim($arguments[0]);
                $message = trim($arguments[1]);

                // A flash key, and a message that is one plain literal.
                // Anything else is an Eloquent eager load or a built string.
                if (! in_array(trim($key, "'\""), self::FLASH_KEYS, true)) {
                    continue;
                }

                if (preg_match("/^'[^']*'$/", $message) !== 1) {
                    continue;
                }

                if (preg_match("/[.!?]'$/", $message) !== 1) {
                    $offenders[] = basename($file).': '.$message;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * Every ApiResponse call of the given kinds, with its arguments.
     *
     * @param  list<string>  $methods
     * @return list<array{0: string, 1: list<string>}>
     */
    private function responseCalls(array $methods): array
    {
        $calls = [];

        foreach ($this->phpFilesIn(self::APP) as $file) {
            if (str_ends_with($file, 'ApiResponse.php')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            foreach ($methods as $method) {
                foreach ($this->argumentsOf($source, 'ApiResponse::'.$method.'(') as $arguments) {
                    $calls[] = [basename($file), $arguments];
                }
            }
        }

        return $calls;
    }
}
