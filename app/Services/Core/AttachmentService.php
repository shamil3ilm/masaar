<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class AttachmentService
{
    /**
     * Upload a file and create attachment record.
     */
    public function upload(
        UploadedFile $file,
        ?Model $attachable = null,
        ?int $organizationId = null,
        array $options = []
    ): Attachment {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        if (!$organizationId) {
            throw new \RuntimeException('Organization ID is required');
        }

        // Determine category from options or guess from MIME type
        $category = $options['category'] ?? $this->guessCategory($file);

        // Validate file
        $this->validateFile($file, $category);

        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . $extension;

        // Determine storage path
        $disk = $options['disk'] ?? config('filesystems.default', 'local');
        $basePath = $options['path'] ?? "attachments/{$organizationId}";
        $path = "{$basePath}/{$fileName}";

        // Store file
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        // Extract metadata
        $metadata = $this->extractMetadata($file);

        // Create attachment record
        $attachment = Attachment::create([
            'organization_id' => $organizationId,
            'attachable_type' => $attachable ? get_class($attachable) : null,
            'attachable_id' => $attachable?->id,
            'file_name' => $fileName,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'category' => $category,
            'description' => $options['description'] ?? null,
            'metadata' => $metadata,
            'visibility' => $options['visibility'] ?? Attachment::VISIBILITY_ORGANIZATION,
            'expires_at' => $options['expires_at'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);

        // Generate thumbnail if image
        if ($attachment->isImage() && ($options['generate_thumbnail'] ?? true)) {
            $this->generateThumbnail($attachment);
        }

        return $attachment;
    }

    /**
     * Upload multiple files.
     */
    public function uploadMultiple(
        array $files,
        ?Model $attachable = null,
        ?int $organizationId = null,
        array $options = []
    ): array {
        $attachments = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $attachments[] = $this->upload($file, $attachable, $organizationId, $options);
            }
        }

        return $attachments;
    }

    /**
     * Attach an existing file (by path) to a model.
     */
    public function attachFromPath(
        string $sourcePath,
        string $originalName,
        ?Model $attachable = null,
        ?int $organizationId = null,
        array $options = []
    ): Attachment {
        $organizationId = $organizationId ?? auth()->user()?->organization_id;

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source file not found: {$sourcePath}");
        }

        $mimeType = mime_content_type($sourcePath);
        $fileSize = filesize($sourcePath);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $fileName = Str::uuid() . '.' . $extension;

        $disk = $options['disk'] ?? config('filesystems.default', 'local');
        $basePath = $options['path'] ?? "attachments/{$organizationId}";
        $path = "{$basePath}/{$fileName}";

        Storage::disk($disk)->put($path, file_get_contents($sourcePath));

        return Attachment::create([
            'organization_id' => $organizationId,
            'attachable_type' => $attachable ? get_class($attachable) : null,
            'attachable_id' => $attachable?->id,
            'file_name' => $fileName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'disk' => $disk,
            'path' => $path,
            'category' => $options['category'] ?? $this->guessCategoryFromMime($mimeType),
            'description' => $options['description'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'visibility' => $options['visibility'] ?? Attachment::VISIBILITY_ORGANIZATION,
            'expires_at' => $options['expires_at'] ?? null,
            'uploaded_by' => auth()->id(),
        ]);
    }

    /**
     * Attach existing attachment to a different model.
     */
    public function attachTo(Attachment $attachment, Model $attachable): Attachment
    {
        // Create a copy if already attached to something else
        if ($attachment->attachable_id && $attachment->attachable_id !== $attachable->id) {
            $newPath = str_replace(
                pathinfo($attachment->path, PATHINFO_FILENAME),
                Str::uuid(),
                $attachment->path
            );

            $attachment->copyTo($newPath);

            return Attachment::create([
                'organization_id' => $attachment->organization_id,
                'attachable_type' => get_class($attachable),
                'attachable_id' => $attachable->id,
                'file_name' => pathinfo($newPath, PATHINFO_BASENAME),
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'file_size' => $attachment->file_size,
                'disk' => $attachment->disk,
                'path' => $newPath,
                'category' => $attachment->category,
                'description' => $attachment->description,
                'metadata' => $attachment->metadata,
                'visibility' => $attachment->visibility,
                'uploaded_by' => auth()->id(),
            ]);
        }

        // Update existing attachment
        $attachment->update([
            'attachable_type' => get_class($attachable),
            'attachable_id' => $attachable->id,
        ]);

        return $attachment;
    }

    /**
     * Detach from model (set attachable to null).
     */
    public function detach(Attachment $attachment): Attachment
    {
        $attachment->update([
            'attachable_type' => null,
            'attachable_id' => null,
        ]);

        return $attachment;
    }

    /**
     * Delete attachment and file.
     */
    public function delete(Attachment $attachment, bool $force = false): bool
    {
        if ($force) {
            $attachment->deleteFile();
            return $attachment->forceDelete();
        }

        return $attachment->delete();
    }

    /**
     * Get attachments for a model.
     */
    public function getForModel(Model $model, ?string $category = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Attachment::where('attachable_type', get_class($model))
            ->where('attachable_id', $model->id);

        if ($category) {
            $query->where('category', $category);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Generate thumbnail for image.
     */
    public function generateThumbnail(Attachment $attachment, int $width = 200, int $height = 200): ?string
    {
        if (!$attachment->isImage()) {
            return null;
        }

        try {
            $thumbPath = str_replace(
                $attachment->file_name,
                'thumb_' . $attachment->file_name,
                $attachment->path
            );

            $image = Image::read(Storage::disk($attachment->disk)->get($attachment->path));
            $image->cover($width, $height);

            Storage::disk($attachment->disk)->put($thumbPath, $image->toJpeg(85));

            // Store thumbnail path in metadata
            $metadata = $attachment->metadata ?? [];
            $metadata['thumbnail_path'] = $thumbPath;
            $attachment->update(['metadata' => $metadata]);

            return $thumbPath;
        } catch (\Exception $e) {
            // Thumbnail generation failed, not critical
            return null;
        }
    }

    /**
     * Validate uploaded file.
     */
    protected function validateFile(UploadedFile $file, string $category): void
    {
        // Check file size
        $maxSize = Attachment::MAX_FILE_SIZES[$category] ?? Attachment::MAX_FILE_SIZES['default'];
        if ($file->getSize() > $maxSize) {
            throw new \RuntimeException(
                "File size exceeds maximum allowed ({$this->formatBytes($maxSize)})"
            );
        }

        // Check MIME type if category has restrictions
        $allowedMimes = Attachment::ALLOWED_MIME_TYPES[$category] ?? null;
        if ($allowedMimes && !in_array($file->getMimeType(), $allowedMimes)) {
            throw new \RuntimeException(
                "File type not allowed for category '{$category}'"
            );
        }

        // Basic security checks
        $extension = strtolower($file->getClientOriginalExtension());
        $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'exe', 'bat', 'cmd', 'sh'];
        if (in_array($extension, $dangerousExtensions)) {
            throw new \RuntimeException('File type not allowed');
        }
    }

    /**
     * Extract metadata from file.
     */
    protected function extractMetadata(UploadedFile $file): array
    {
        $metadata = [];

        // Image metadata
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['channels'] = $imageInfo['channels'] ?? null;
                $metadata['bits'] = $imageInfo['bits'] ?? null;
            }
        }

        // PDF metadata
        if ($file->getMimeType() === 'application/pdf') {
            // Could use a PDF library to extract page count, etc.
            $metadata['estimated'] = true;
        }

        return $metadata;
    }

    /**
     * Guess category from file.
     */
    protected function guessCategory(UploadedFile $file): string
    {
        return $this->guessCategoryFromMime($file->getMimeType());
    }

    /**
     * Guess category from MIME type.
     */
    protected function guessCategoryFromMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return Attachment::CATEGORY_IMAGE;
        }

        if ($mimeType === 'application/pdf' ||
            str_contains($mimeType, 'word') ||
            str_contains($mimeType, 'excel') ||
            str_contains($mimeType, 'spreadsheet') ||
            str_contains($mimeType, 'text/')) {
            return Attachment::CATEGORY_DOCUMENT;
        }

        return Attachment::CATEGORY_OTHER;
    }

    /**
     * Format bytes for human reading.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get storage statistics for organization.
     */
    public function getStorageStats(int $organizationId): array
    {
        $attachments = Attachment::where('organization_id', $organizationId);

        return [
            'total_files' => (clone $attachments)->count(),
            'total_size' => (clone $attachments)->sum('file_size'),
            'total_size_formatted' => $this->formatBytes((int)(clone $attachments)->sum('file_size')),
            'by_category' => (clone $attachments)
                ->selectRaw('category, count(*) as count, sum(file_size) as size')
                ->groupBy('category')
                ->get()
                ->mapWithKeys(fn($row) => [$row->category => [
                    'count' => $row->count,
                    'size' => $row->size,
                    'size_formatted' => $this->formatBytes($row->size),
                ]])
                ->toArray(),
            'by_type' => (clone $attachments)
                ->selectRaw('mime_type, count(*) as count')
                ->groupBy('mime_type')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'mime_type')
                ->toArray(),
        ];
    }

    /**
     * Clean up orphaned attachments (not attached to any model).
     */
    public function cleanupOrphaned(int $daysOld = 30): int
    {
        $deleted = 0;

        Attachment::whereNull('attachable_type')
            ->where('created_at', '<', now()->subDays($daysOld))
            ->chunk(100, function ($attachments) use (&$deleted) {
                foreach ($attachments as $attachment) {
                    $this->delete($attachment, true);
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Clean up expired attachments.
     */
    public function cleanupExpired(): int
    {
        $deleted = 0;

        Attachment::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunk(100, function ($attachments) use (&$deleted) {
                foreach ($attachments as $attachment) {
                    $this->delete($attachment, true);
                    $deleted++;
                }
            });

        return $deleted;
    }
}
