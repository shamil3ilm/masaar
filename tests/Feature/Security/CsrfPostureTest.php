<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * The Blade surfaces mutate state, so they need CSRF, and they have it: the
 * `web` group carries ValidateCsrfToken and every form that posts carries the
 * token.
 *
 * This is asserted as posture rather than behaviour because the middleware
 * stands itself down inside PHPUnit — ValidateCsrfToken::handle() returns
 * early when runningUnitTests() is true. A test posting without a token
 * therefore gets 200 and proves nothing about production, which is exactly the
 * shape of assertion that looks like coverage and is not.
 *
 * What can be checked is what a person would otherwise have to remember: that
 * no route was added to the exception list, and that a new form was not
 * written without @csrf.
 */
class CsrfPostureTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    public function test_web_group_validates_csrf(): void
    {
        $this->assertContains(
            ValidateCsrfToken::class,
            $this->webMiddleware(),
            'The web group no longer validates CSRF tokens.'
        );
    }

    /**
     * An exception is a state-changing route deliberately left open. There are
     * none, and adding one should be a decision somebody makes on purpose.
     */
    public function test_no_route_is_exempt_from_csrf(): void
    {
        $middleware = $this->app->make(ValidateCsrfToken::class);

        $except = (fn () => $this->except)->call($middleware);

        $this->assertSame([], $except, 'These routes skip CSRF: '.implode(', ', $except));
    }

    public function test_every_posting_form_carries_the_token(): void
    {
        $missing = [];

        foreach ($this->blades() as $path) {
            $body = (string) file_get_contents($path);

            preg_match_all('/<form\b.*?<\/form>/is', $body, $forms);

            foreach ($forms[0] as $form) {
                // A GET form is a filter or a link with fields; it changes
                // nothing and needs no token.
                if (! preg_match('/method\s*=\s*["\']post["\']/i', $form)) {
                    continue;
                }

                if (! str_contains($form, '@csrf')) {
                    $name = str_replace(self::VIEWS.DIRECTORY_SEPARATOR, '', $path);
                    $missing[] = $name.': '.trim(explode('>', $form)[0]).'>';
                }
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These forms post without a CSRF token:\n  %s",
            implode("\n  ", $missing)
        ));
    }

    /**
     * @return list<string>
     */
    private function webMiddleware(): array
    {
        $kernel = $this->app->make(Kernel::class);

        $groups = (fn () => $this->middlewareGroups)->call($kernel);

        return array_values(array_filter(
            $groups['web'] ?? [],
            static fn ($middleware) => is_string($middleware)
        ));
    }

    /**
     * @return list<string>
     */
    private function blades(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEWS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
