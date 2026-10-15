<?php

declare(strict_types=1);

namespace App\Domains\Branding\Services;

use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Support\Xml;
use App\Support\XmlException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploaded logos and favicons.
 *
 * An image someone uploads is served back to everyone who opens the portal,
 * so it is checked as what it claims to be. A raster image has to decode as
 * one. An SVG is a document that can carry script, so it is parsed - with no
 * DOCTYPE and no network, through the same gate as every other XML here - and
 * refused if it holds anything that runs or fetches: script, event handlers,
 * embedded HTML, or a link anywhere but inside itself. ThemeAssetController
 * also serves it sandboxed, so neither check alone is what stands in the way.
 */
final class BrandAssets
{
    public const DISK = 'branding';

    public const MAX_KILOBYTES = 512;

    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    /**
     * Check and keep an upload.
     *
     * @param  'logo'|'favicon'  $kind
     * @return array{path: string, type: string}
     *
     * @throws InvalidThemeException
     */
    public function store(UploadedFile $file, string $scope, string $kind): array
    {
        $label = $kind === 'logo' ? 'The logo' : 'The favicon';

        if (! $file->isValid()) {
            throw new InvalidThemeException(["{$label} did not upload completely."]);
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw new InvalidThemeException(["{$label} must be at most ".self::MAX_KILOBYTES.' KB.']);
        }

        $type = $this->typeOf($file);

        if ($type === null) {
            throw new InvalidThemeException(["{$label} must be a PNG, JPEG, WebP or SVG image."]);
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if ($type === 'image/svg+xml') {
            $this->refuseActiveSvg($contents, $label);
        } elseif (@getimagesizefromstring($contents) === false) {
            throw new InvalidThemeException(["{$label} is not a readable image."]);
        }

        $path = $scope.'/'.$kind.'-'.Str::lower(Str::random(16)).'.'.self::TYPES[$type];
        Storage::disk(self::DISK)->put($path, $contents);

        return ['path' => $path, 'type' => $type];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    /**
     * The type the content is, not the type the browser said it was.
     */
    private function typeOf(UploadedFile $file): ?string
    {
        $detected = (string) $file->getMimeType();

        // finfo reports a plain-text SVG without an XML declaration as text.
        if (in_array($detected, ['text/plain', 'text/xml', 'application/xml'], true)
            && strtolower((string) $file->getClientOriginalExtension()) === 'svg') {
            $detected = 'image/svg+xml';
        }

        return array_key_exists($detected, self::TYPES) ? $detected : null;
    }

    /**
     * @throws InvalidThemeException
     */
    private function refuseActiveSvg(string $svg, string $label): void
    {
        $dom = new DOMDocument;

        try {
            Xml::load($dom, $svg, LIBXML_NONET, self::MAX_KILOBYTES * 1024);
        } catch (XmlException) {
            throw new InvalidThemeException(["{$label} is not a well-formed SVG."]);
        }

        if (strtolower((string) $dom->documentElement?->localName) !== 'svg') {
            throw new InvalidThemeException(["{$label} is not an SVG image."]);
        }

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//*') as $element) {
            /** @var DOMElement $element */
            if (in_array(strtolower($element->localName), ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler'], true)) {
                throw new InvalidThemeException(["{$label} contains a {$element->localName} element, which can run code."]);
            }

            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = strtolower(trim($attribute->nodeValue ?? ''));

                if (str_starts_with($name, 'on')) {
                    throw new InvalidThemeException(["{$label} contains an event handler ({$attribute->nodeName})."]);
                }

                if (in_array($name, ['href', 'xlink:href', 'src'], true) && $value !== '' && ! str_starts_with($value, '#')) {
                    throw new InvalidThemeException(["{$label} links outside itself, which an embedded image must not."]);
                }

                if (str_contains($value, 'javascript:') || str_contains($value, 'url(http') || str_contains($value, '@import')) {
                    throw new InvalidThemeException(["{$label} refers to something outside itself."]);
                }
            }

            if (strtolower($element->localName) === 'style' && preg_match('/@import|url\(\s*[\'"]?(?!#)/i', $element->textContent)) {
                throw new InvalidThemeException(["{$label} loads styles from outside itself."]);
            }
        }
    }
}
