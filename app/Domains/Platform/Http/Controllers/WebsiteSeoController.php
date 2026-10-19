<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Response;
use XMLWriter;

/**
 * What a search engine reads about the public site.
 *
 * sitemap.xml lists each public page once per language, at the address
 * that opens it in that language (?lang=, SetLocale), with every language's
 * address and the language-neutral one as its alternates (hreflang, as the
 * pages' own heads say). robots.txt points at it and keeps crawlers out of
 * what is not public: the console, the portal, the API, invoice links and
 * invitations. Both are built from APP_URL, so they are right on any
 * deployment; public/robots.txt is gone, because a file there is served
 * before the application is reached.
 */
class WebsiteSeoController extends Controller
{
    /** Never crawled: private areas, and links whose token is the key. */
    private const PRIVATE = ['/admin/', '/portal/', '/api/', '/i/', '/invitations/', '/reset-password/'];

    public function sitemap(): Response
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach (SetLocale::PUBLIC_PAGES as $page) {
            foreach (SetLocale::SUPPORTED as $locale) {
                $xml->startElement('url');
                $xml->writeElement('loc', SetLocale::addressFor($page, $locale));

                foreach ([...SetLocale::SUPPORTED, 'x-default'] as $alternate) {
                    $xml->startElement('xhtml:link');
                    $xml->writeAttribute('rel', 'alternate');
                    $xml->writeAttribute('hreflang', $alternate);
                    $xml->writeAttribute('href', SetLocale::addressFor($page, $alternate === 'x-default' ? null : $alternate));
                    $xml->endElement();
                }

                $xml->endElement();
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        foreach (self::PRIVATE as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.route('sitemap');

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
