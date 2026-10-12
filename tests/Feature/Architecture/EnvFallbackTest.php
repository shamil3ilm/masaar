<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Asking the environment what environment this is needs the same fallback
 * everywhere.
 *
 * `config/app.php` reads `env('APP_ENV', 'production')`, so with no `.env` at
 * all the application considers itself production. `config/session.php` read
 * `env('APP_ENV') === 'production'` to decide whether to encrypt sessions,
 * and `env()` with no fallback returns null - so in that same environment it
 * decided *not* to encrypt. Two files, one question, two answers.
 *
 * That is not a theoretical disagreement. `AppServiceProvider` refuses to boot
 * a production deployment whose sessions are unencrypted, and the one place
 * with no `.env` is every fresh checkout - including CI, whose first step is
 * `composer install`, which runs `package:discover` through
 * post-autoload-dump before any `.env` is written. The guard saw production
 * with cleartext sessions, threw, and took down four jobs and the image build
 * on a branch whose tests were all green locally, where a `.env` exists.
 *
 * So: a default that turns on the environment name must read it the way the
 * framework does. `env('APP_ENV')` alone is the bug, and it is invisible until
 * something runs without a `.env`.
 */
class EnvFallbackTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * Where a default could be computed from the environment name.
     *
     * @var list<string>
     */
    private const SEARCH = ['config', 'app'];

    public function test_app_env_is_read_with_fallback(): void
    {
        $found = [];

        foreach ($this->sources() as $path) {
            $source = $this->codeOf($path);

            // env('APP_ENV') with nothing after the quote but the closing
            // bracket. env('APP_ENV', 'production') is the correct form and
            // does not match.
            if (preg_match_all("/env\(\s*'APP_ENV'\s*\)/", $source, $m) > 0) {
                $found[] = $this->relative($path).': '.count($m[0])
                    .' use(s) of env(\'APP_ENV\') with no fallback';
            }
        }

        $this->assertSame([], $found, implode("\n", $found)."\n\n"
            .'config/app.php reads env(\'APP_ENV\', \'production\'), so with no .env '
            .'the application is production. Read without that fallback the same '
            .'expression is null, and a default computed from it disagrees with the '
            .'application about where it is running - silently, and only where there '
            .'is no .env, which is every fresh checkout and every CI job before it '
            .'writes one.');
    }

    /**
     * A file's code with its comments removed.
     *
     * Scanning the raw text matched the comment in config/session.php that
     * explains this very mistake, which is the sort of false positive that
     * gets a check deleted rather than fixed. Prose about a bug is not the
     * bug.
     */
    private function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $out = [];

        foreach (self::SEARCH as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::ROOT.'/'.$dir)
            );

            foreach ($it as $file) {
                if (! $file->isDir() && str_ends_with($file->getFilename(), '.php')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        return $out;
    }

    private function relative(string $path): string
    {
        $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        foreach (self::SEARCH as $dir) {
            $at = strrpos($normalised, '/'.$dir.'/');

            if ($at !== false) {
                return substr($normalised, $at + 1);
            }
        }

        return $normalised;
    }
}
