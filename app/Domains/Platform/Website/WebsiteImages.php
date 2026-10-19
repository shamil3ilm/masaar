<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Images uploaded for the public website: a testimonial's logo, the picture
 * shown when the site is shared.
 *
 * Shown to every visitor, so each is checked as what it is, not what the
 * browser called it: a PNG, JPEG or WebP that decodes as one, within a size
 * and a pixel limit. No SVG - a document that can carry script, and no
 * site that shows a shared link draws one anyway. Kept on a disk of its own
 * outside the web root under a random name, and served by the website's
 * routes with nosniff and a sandboxing content policy.
 */
final class WebsiteImages
{
    public const DISK = 'website';

    /** The widest or tallest an image may be, in pixels. */
    public const MAX_PIXELS = 4096;

    private const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * Check and keep an upload under a folder.
     *
     * @return array{path: string, type: string}
     *
     * @throws ValidationException keyed by $field
     */
    public function store(UploadedFile $file, string $folder, int $maxKilobytes, string $field): array
    {
        $refuse = fn (string $message): ValidationException => ValidationException::withMessages([$field => $message]);

        if (! $file->isValid()) {
            throw $refuse(__('The image did not upload completely.'));
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw $refuse(__('The image must be at most :size KB.', ['size' => $maxKilobytes]));
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($contents);
        $type = is_array($info) ? (self::IMAGE_TYPES[$info[2]] ?? null) : null;

        // The bytes and the detected type must agree: a decoder's verdict
        // alone would pass a file that is something else as well.
        if ($type === null || $type !== $file->getMimeType()) {
            throw $refuse(__('The image must be a PNG, JPEG or WebP.'));
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            throw $refuse(__('The image must be at most :pixels pixels wide and high.', ['pixels' => self::MAX_PIXELS]));
        }

        $path = $folder.'/'.Str::lower(Str::random(24)).'.'.self::TYPES[$type];
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
}
