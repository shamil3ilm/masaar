<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\LogSanitizer;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Run every log record's context through LogSanitizer on its way out.
 *
 * LogSanitizer is thorough - PEM blocks, bearer tokens, JWTs, base64 key
 * blobs, and a field list covering privateKey, secret, token, pem and
 * signed_xml - and until now it protected nothing. Its only production call
 * site was ComplianceLogger::log(), and ComplianceLogger was registered as a
 * singleton in AppServiceProvider and injected nowhere: no controller,
 * service, job, listener or command ever resolved it. So all ninety-odd
 * Log::* calls in app/ wrote unsanitised context, and LogContext's docblock
 * cited the sanitiser as the reason it restricts itself to identifiers -
 * relying on a net that was not attached at either end.
 *
 * A tap rather than a base logger class, because the leak is not in any one
 * call site. Two of the worst write a third party's entire response body:
 * FatooraClient logs $response->body() for every non-2xx on the
 * clearance path, which on a warning clearance carries clearedInvoice - the
 * signed document - and UsageReportingService logs the licence server's
 * response to a request that sent the platform licence key in its body. Those
 * are strings, not fields, which is why the sanitiser's pattern matching
 * matters as much as its field list. Fixing them one at a time would leave
 * the next one to be found by whoever reads the log.
 *
 * Declared on every channel in config/logging.php, and LogRedactionTest holds
 * that, because a channel added without it is exactly how this comes back.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->pushProcessor(static function (LogRecord $record): LogRecord {
                // Monolog 3 records are immutable, so this returns a copy
                // rather than mutating what the caller still holds.
                return $record->with(
                    message: LogSanitizer::sanitizeString($record->message),
                    context: LogSanitizer::context($record->context),
                );
            });
        }
    }
}
