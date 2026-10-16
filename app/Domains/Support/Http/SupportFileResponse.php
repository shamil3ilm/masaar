<?php

declare(strict_types=1);

namespace App\Domains\Support\Http;

use App\Domains\Support\Models\SupportAttachment;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * A ticket attachment, always as a download.
 *
 * Never shown in the page: whatever a file turns out to contain, a browser
 * that saves it does not run it.
 */
final class SupportFileResponse
{
    public static function for(SupportAttachment $attachment, string $path): BinaryFileResponse
    {
        return response()->download($path, (string) $attachment->name, [
            'Content-Type' => (string) $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }
}
