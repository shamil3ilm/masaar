<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to ticket messages: a screenshot, an invoice's XML, the
 * error a ZATCA response gave.
 *
 * The type is read from the content, not from the name or what the browser
 * claimed. Files are never shown in the page - every download is served as
 * an attachment, so an HTML or SVG file renamed .png cannot run in it.
 */
final class TicketFiles
{
    public const DISK = 'support';

    public const MAX_FILES = 3;

    public const MAX_KILOBYTES = 5120;

    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/xml' => 'xml',
        'application/xml' => 'xml',
    ];

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws DomainException
     */
    public function check(array $files): void
    {
        if (count($files) > self::MAX_FILES) {
            throw new DomainException('Attach at most '.self::MAX_FILES.' files to one message.');
        }

        foreach ($files as $file) {
            if (! $file->isValid()) {
                throw new DomainException($file->getClientOriginalName().' did not upload completely.');
            }

            if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
                throw new DomainException($file->getClientOriginalName().' is over '.(self::MAX_KILOBYTES / 1024).' MB.');
            }

            if (! isset(self::TYPES[(string) $file->getMimeType()])) {
                throw new DomainException($file->getClientOriginalName().' is not a PDF, image, XML, CSV or text file.');
            }
        }
    }

    /**
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $ticketId): array
    {
        $mime = (string) $file->getMimeType();
        $path = $ticketId.'/'.Str::lower(Str::random(24)).'.'.self::TYPES[$mime];

        Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'path' => $path,
            'name' => $this->safeName($file->getClientOriginalName()),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ];
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function deleteTicket(string $ticketId): void
    {
        Storage::disk(self::DISK)->deleteDirectory($ticketId);
    }

    /**
     * The name shown and offered on download, without anything a header or
     * a file system would read as structure.
     */
    private function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^\w.\- ]+/u', '_', basename($name)));

        return Str::limit($clean === '' ? 'attachment' : $clean, 120, '');
    }
}
