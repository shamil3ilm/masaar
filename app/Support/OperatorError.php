<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * What an operator is told when an admin action fails.
 *
 * The admin screens put the exception's own message in the banner. For the
 * refusals written to be read - a licence that is already revoked, a switch
 * that does not exist - that is right. For anything else it put SQL, file
 * paths and library internals on a screen, and into whatever screenshot of it
 * was shared. Those now get a sentence and a reference, and the detail goes
 * to the log under the same reference.
 */
final class OperatorError
{
    /**
     * @param  string  $what  What was attempted, e.g. "Issuing the licence".
     * @param  list<class-string<Throwable>>  $explained  Exceptions whose
     *                                                    message is written for
     *                                                    the operator.
     */
    public static function message(Throwable $e, string $what, array $explained = []): string
    {
        foreach ($explained as $class) {
            if ($e instanceof $class) {
                return $e->getMessage();
            }
        }

        $reference = strtoupper(Str::random(8));

        Log::error("{$what} failed", ['reference' => $reference, 'exception' => $e]);

        return "{$what} failed unexpectedly. Reference {$reference}: the details are in the application log.";
    }
}
