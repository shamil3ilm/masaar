<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use DomainException;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The receipt or supplier invoice behind an expense: a PDF or an image, on
 * the private receipts disk.
 *
 * The type is read from the content, not from the name or what the browser
 * claimed, and only these four are kept - so what is shown in the page as a
 * preview is only ever an image, and a PDF opens on its own.
 */
final class ExpenseReceipts
{
    public const DISK = 'receipts';

    public const MAX_KILOBYTES = 10240;

    public const ACCEPT = 'application/pdf,image/png,image/jpeg,image/webp';

    private const TYPES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{receipt_path: string, receipt_name: string, receipt_mime: string, receipt_size: int}
     *
     * @throws DomainException when it is not a PDF or an image, or too large
     */
    public function store(UploadedFile $file, string $expenseId): array
    {
        if (! $file->isValid()) {
            throw new DomainException(__(':name did not upload completely.', ['name' => $file->getClientOriginalName()]));
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw new DomainException(__(':name is over :size MB.', ['name' => $file->getClientOriginalName(), 'size' => self::MAX_KILOBYTES / 1024]));
        }

        // Read from the bytes themselves, whatever the name says.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! isset(self::TYPES[$mime])) {
            throw new DomainException(__(':name is not a PDF or an image.', ['name' => $file->getClientOriginalName()]));
        }

        $path = $expenseId.'/'.Str::lower(Str::random(24)).'.'.self::TYPES[$mime];
        Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'receipt_path' => $path,
            'receipt_name' => $this->safeName($file->getClientOriginalName()),
            'receipt_mime' => $mime,
            'receipt_size' => (int) $file->getSize(),
        ];
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function exists(?string $path): bool
    {
        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }

    public static function isImage(?string $mime): bool
    {
        return $mime !== null && str_starts_with($mime, 'image/');
    }

    /**
     * The name shown and offered on download, without anything a header or
     * a file system would read as structure.
     */
    private function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^\w.\- ]+/u', '_', basename($name)));

        return Str::limit($clean === '' ? 'receipt' : $clean, 120, '');
    }
}
