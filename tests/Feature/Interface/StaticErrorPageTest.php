<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Console\Commands\WriteStaticErrorPages;
use Tests\TestCase;

/**
 * The page nginx shows when PHP is not there to answer: php-fpm down,
 * restarting or too slow (502, 503, 504).
 *
 * Nothing of the application runs then, so the page is a file - written
 * from config/brand.php by `php artisan platform:static-errors`, committed,
 * and written again where the image is built and where it starts. These
 * fail when the committed file is not what the brand would write, when it
 * would need anything the moment cannot give it, or when nginx would not
 * serve it with the status that happened.
 *
 * No database here, deliberately: the command must not need one.
 */
class StaticErrorPageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_committed_pages_match_the_brand(): void
    {
        foreach ($this->app->make(WriteStaticErrorPages::class)->pages() as $path => $contents) {
            $this->assertFileExists(public_path($path));
            $this->assertSame(
                $contents,
                str_replace("\r\n", "\n", (string) file_get_contents(public_path($path))),
                "public/{$path} is out of date with the brand. Run: php artisan platform:static-errors",
            );
        }
    }

    public function test_the_page_follows_a_rename(): void
    {
        config(['brand.name' => 'Nibras', 'brand.name_ar' => 'نبراس']);

        $html = $this->app->make(WriteStaticErrorPages::class)->pages()['50x.html'];

        $this->assertStringContainsString('Nibras', $html);
        $this->assertStringContainsString('نبراس', $html);
    }

    public function test_the_page_speaks_both_languages(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString(e(__('Try again', [], 'en')), $html);
        $this->assertStringContainsString(e(__('Try again', [], 'ar')), $html);
    }

    /**
     * Under the content policy nginx sends - style-src 'self', script-src
     * 'self' - and with nothing behind it that could answer another request.
     */
    public function test_the_page_stands_on_its_own(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/', $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html, 'The page fetches from another origin.');
        $this->assertStringContainsString('<svg', $html, 'The mark is drawn inline.');

        preg_match_all('/<link[^>]+href="([^"]+)"/', $html, $links);
        $this->assertNotSame([], $links[1]);

        foreach ($links[1] as $href) {
            $this->assertStringStartsWith('/', $href, 'Root-relative: the address in the bar is whatever failed.');
            $this->assertFileExists(public_path(ltrim((string) parse_url($href, PHP_URL_PATH), '/')));
        }
    }

    /** The way out is to ask again, and only that: nothing else is running. */
    public function test_the_only_link_retries(): void
    {
        preg_match_all('/<a\s[^>]*href="([^"]*)"/', $this->html(), $links);

        $this->assertNotSame([], $links[1]);
        $this->assertSame([''], array_values(array_unique($links[1])), 'Every link on the page reloads the address that failed.');
    }

    /** An API client is answered in the envelope, as by the application. */
    public function test_api_clients_get_the_envelope(): void
    {
        $body = json_decode($this->app->make(WriteStaticErrorPages::class)->pages()['50x.json'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($body['success']);
        $this->assertSame('SERVICE_UNAVAILABLE', $body['error']['code']);
        $this->assertTrue($body['error']['retryable']);
    }

    /**
     * Served from public/, internally only, and with the status that
     * happened: an `=` in error_page would answer 200, and a crawler or a
     * client would take the page for the content.
     */
    public function test_nginx_serves_them_with_their_status(): void
    {
        $config = (string) file_get_contents(self::ROOT.'/docker/nginx/default.conf');

        $this->assertMatchesRegularExpression('#^\s*error_page\s+500 502 503 504\s+/50x\.\$\w+;#m', $config);
        $this->assertDoesNotMatchRegularExpression('/error_page[^;]*=/', $config, 'error_page must keep the status.');
        $this->assertMatchesRegularExpression('#~\^/\(api\|v\[0-9\]\+\)/\s+json;#', $config, 'API addresses are answered in JSON.');

        foreach (['50x.html', '50x.json'] as $page) {
            preg_match('#location = /'.preg_quote($page, '#').' \{(.*?)\}#s', $config, $block);

            $this->assertNotSame([], $block, "No location serves /{$page}.");
            $this->assertStringContainsString('internal;', $block[1]);
            $this->assertStringNotContainsString('root ', $block[1], "/{$page} is served from public/, the server's root.");
        }
    }

    public function test_the_image_writes_them(): void
    {
        $this->assertStringContainsString('php artisan platform:static-errors', (string) file_get_contents(self::ROOT.'/Dockerfile'));
        $this->assertStringContainsString('php artisan platform:static-errors', (string) file_get_contents(self::ROOT.'/docker/entrypoint.sh'));
    }

    private function html(): string
    {
        return $this->app->make(WriteStaticErrorPages::class)->pages()['50x.html'];
    }
}
