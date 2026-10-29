<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\SafeFetch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound fetches must not follow redirects.
 *
 * SafeUrl decides which address may be contacted, and a fetch that follows a
 * redirect leaves that decision behind: an allowlisted host answering 302 to
 * 169.254.169.254 defeats the check entirely.
 *
 * SafeFetch::options() is asserted as configuration too — that the array
 * contains allow_redirects => false. That proves what we asked for,
 * not what the client does with it, and the way this control fails is by the option
 * being placed under the wrong key or spelled slightly wrong, where an
 * array-shape assertion still passes.
 *
 * So this runs a real server and a real redirect. It cannot go through
 * SafeFetch::get(), which refuses loopback addresses and non-https schemes long
 * before any socket opens; the options are applied to the client directly,
 * which is the part whose behaviour is in question.
 */
class RedirectPolicyTest extends TestCase
{
    private ?string $router = null;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->port = $this->freePort();
        $this->router = tempnam(sys_get_temp_dir(), 'router').'.php';

        file_put_contents($this->router, <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            if ($path === '/redirect') {
                header('Location: /target', true, 302);
                echo 'REDIRECTED';
            } elseif ($path === '/target') {
                echo 'ARRIVED';
            } else {
                http_response_code(404);
            }

            return true;
            PHP);

        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $this->router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($this->server) || ! $this->waitForPort()) {
            $this->markTestSkipped('Could not start a local HTTP server on port '.$this->port.'.');
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->router !== null && file_exists($this->router)) {
            unlink($this->router);
        }

        parent::tearDown();
    }

    /**
     * The control. Without it a server that never came up would make the test
     * below pass by never arriving anywhere.
     */
    public function test_target_is_reachable_directly(): void
    {
        [$body, $status] = $this->fetch('/target');

        $this->assertSame(200, $status);
        $this->assertSame('ARRIVED', $body);
    }

    public function test_redirects_are_not_followed(): void
    {
        [$body, $status] = $this->fetch('/redirect');

        // The body is what decides this. The status only establishes that the
        // server did redirect — without which the body check below would be
        // vacuous.
        $this->assertSame(302, $status, 'The server did not issue a redirect.');

        // 'ARRIVED' is only reachable by following the Location header.
        $this->assertSame('REDIRECTED', $body, 'The fetch followed the redirect to its target.');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function fetch(string $path): array
    {
        $url = 'http://127.0.0.1:'.$this->port.$path;
        $response = Http::withOptions(SafeFetch::options($url, null))->get($url);

        return [$response->body(), $response->status()];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    private function waitForPort(): bool
    {
        // The server forks and binds asynchronously, so the first connection
        // can arrive before it is listening.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);

            if (is_resource($probe)) {
                fclose($probe);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
