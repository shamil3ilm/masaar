<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Logging\RedactSecrets;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Secrets do not reach the log file, on any channel.
 *
 * LogSanitizer is a thorough redactor - PEM blocks, bearer tokens, JWTs,
 * base64 key blobs, and a field list covering privateKey, secret, token, pem
 * and signed_xml - and it protected nothing. Its only production call site was
 * ComplianceLogger::log(), and ComplianceLogger was registered as a singleton
 * in AppServiceProvider and injected nowhere: no controller, service, job,
 * listener or command resolved it. So every Log::* call in app/ wrote
 * unsanitised context, and LogContext's docblock cited the sanitiser as its
 * reason for restricting itself to identifiers - relying on a net attached at
 * neither end.
 *
 * It is a tap on every channel now, rather than something each call site has
 * to remember, because the leak was never in one call site. Two of the worst
 * write a third party's whole response body: FatooraClient logs
 * $response->body() for every non-2xx on the clearance path, which on a
 * warning clearance carries clearedInvoice - the signed document - and
 * UsageReportingService logs the licence server's reply to a request that
 * sent the platform licence key in its body. Those are strings rather than
 * fields, which is why the pattern matching matters as much as the field list.
 */
class LogRedactionTest extends TestCase
{
    /**
     * Channels that write nothing of their own: null discards, emergency is a
     * path, and stack delegates to members that carry their own tap.
     *
     * @var list<string>
     */
    private const AGGREGATORS = ['stack', 'null', 'emergency'];

    public function test_every_channel_redacts(): void
    {
        $missing = [];

        foreach (config('logging.channels') as $name => $channel) {
            if (in_array($name, self::AGGREGATORS, true)) {
                continue;
            }

            if (! in_array(RedactSecrets::class, $channel['tap'] ?? [], true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'these channels write unredacted context: '.implode(', ', $missing));
    }

    /**
     * A named field is redacted.
     */
    public function test_a_secret_field_is_redacted(): void
    {
        $record = $this->write(['api_key' => 'cp_live_abcdefghijklmnop', 'invoice_id' => 'keep-me']);

        $this->assertStringNotContainsString('abcdefghijklmnop', json_encode($record->context));
        $this->assertSame('keep-me', $record->context['invoice_id'], 'an identifier was redacted too');
    }

    /**
     * And so is a secret that arrives inside a string, which is how the two
     * response-body cases leak.
     */
    public function test_a_body_secret_is_redacted(): void
    {
        $body = '{"error":"bad key","license_key":"cp_live_THISISTHEKEY"}';

        $record = $this->write(['body' => $body]);

        $this->assertStringNotContainsString(
            'THISISTHEKEY',
            json_encode($record->context),
            'a credential echoed back inside a third party response body reached the log'
        );
    }

    public function test_a_private_key_is_redacted(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIBVgIBADANBgkqh\n-----END PRIVATE KEY-----";

        $record = $this->write(['pem' => $pem]);

        $this->assertStringNotContainsString('MIIBVgIBADANBgkqh', json_encode($record->context));
    }

    /**
     * Write one record through the tap and hand back what the handler saw.
     *
     * @param  array<string, mixed>  $context
     */
    private function write(array $context): LogRecord
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('test', [$handler]));

        (new RedactSecrets)($logger);

        $logger->warning('probe', $context);

        $records = $handler->getRecords();

        $this->assertCount(1, $records, 'the record never reached the handler');

        return $records[0];
    }
}
