<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The image may not start as a user that cannot do what its init expects.
 *
 * The Dockerfile ended with `USER masaar`, under a comment saying nginx would
 * run as root and then drop privileges - which that very line made
 * impossible. A process cannot acquire a privilege it was not started with.
 *
 * Everything in the image assumes supervisord holds it:
 *
 *   supervisord.conf declares [supervisord] user=root, and supervisord
 *   started as a non-root user refuses to run rather than pretend it dropped;
 *   nginx and php-fpm are declared with no user= because their masters are
 *   meant to be root, one to bind :80 and open /var/log/nginx and the other
 *   to hand its pool to www-data;
 *   the queue workers carry user=masaar, which is a per-program drop that
 *   only works from root;
 *   entrypoint.sh guards its chown with `if [ "$(id -u)" = "0" ]`.
 *
 * So the container could not have served a request. It went unnoticed because
 * nothing has ever run this image: the deployment guide installs onto a host
 * directly, and the image job in CI builds and scans without starting it. A
 * `nginx -t` step added at the same time as this test is what surfaced it -
 * nginx could not open its error log as uid 1000.
 *
 * The invariant is a conditional one rather than "never set USER". An image
 * whose init does not need root should absolutely run as a user, and the
 * right end state here is a rootless container - nginx on 8080, no user=root
 * in supervisord, the published port remapped. This fails only on the
 * combination that cannot work: an init declaring user=root under a
 * non-root USER.
 */
class ContainerUserTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_no_user_contradicts_supervisord(): void
    {
        $supervisord = (string) file_get_contents(self::ROOT.'/docker/supervisor/supervisord.conf');

        $wantsRoot = preg_match('/^\s*user\s*=\s*root\s*$/m', $this->supervisordBlock($supervisord)) === 1;

        $dockerfile = (string) file_get_contents(self::ROOT.'/Dockerfile');

        preg_match_all('/^USER\s+(\S+)/m', $dockerfile, $matches);

        $last = $matches[1] === [] ? 'root' : end($matches[1]);

        if (! $wantsRoot) {
            $this->assertTrue(true, 'supervisord does not ask for root, so any USER is its own business.');

            return;
        }

        $this->assertSame('root', $last,
            'supervisord.conf declares [supervisord] user=root and the Dockerfile ends '
            .'with USER '.$last.'. supervisord started as a non-root user refuses to run, '
            .'so the container would not serve a request - and nothing in CI starts it, '
            .'so nothing would say so. Either drop the USER line or make the image '
            .'rootless properly: nginx on an unprivileged port, no user=root in '
            .'supervisord, and the published port remapped.');
    }

    /**
     * The [supervisord] section alone.
     *
     * A per-program `user=` is a privilege drop and is exactly what should be
     * there; only the one in the daemon's own section says how it must start.
     */
    private function supervisordBlock(string $config): string
    {
        $start = strpos($config, '[supervisord]');

        if ($start === false) {
            return '';
        }

        $rest = substr($config, $start + strlen('[supervisord]'));
        $next = strpos($rest, "\n[");

        return $next === false ? $rest : substr($rest, 0, $next);
    }
}
