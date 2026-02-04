<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Core\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;

/**
 * Trait for models that can have file attachments.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Attachment> $attachments
 */
trait HasAttachments
{
    /**
     * Get all attachments for this model.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get attachments by category.
     */
    public function attachmentsByCategory(string $category): MorphMany
    {
        return $this->attachments()->where('category', $category);
    }

    /**
     * Get images attached to this model.
     */
    public function images(): MorphMany
    {
        return $this->attachments()->where('category', Attachment::CATEGORY_IMAGE);
    }

    /**
     * Get documents attached to this model.
     */
    public function documents(): MorphMany
    {
        return $this->attachments()->where('category', Attachment::CATEGORY_DOCUMENT);
    }

    /**
     * Get receipts attached to this model.
     */
    public function receipts(): MorphMany
    {
        return $this->attachments()->where('category', Attachment::CATEGORY_RECEIPT);
    }

    /**
     * Add an attachment to this model.
     */
    public function addAttachment(UploadedFile $file, array $options = []): Attachment
    {
        return app(\App\Services\Core\AttachmentService::class)->upload(
            $file,
            $this,
            $this->organization_id ?? auth()->user()?->organization_id,
            $options
        );
    }

    /**
     * Add multiple attachments.
     */
    public function addAttachments(array $files, array $options = []): array
    {
        return app(\App\Services\Core\AttachmentService::class)->uploadMultiple(
            $files,
            $this,
            $this->organization_id ?? auth()->user()?->organization_id,
            $options
        );
    }

    /**
     * Check if model has any attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->attachments()->exists();
    }

    /**
     * Get attachment count.
     */
    public function getAttachmentCount(): int
    {
        return $this->attachments()->count();
    }

    /**
     * Get total size of all attachments.
     */
    public function getTotalAttachmentSize(): int
    {
        return $this->attachments()->sum('file_size');
    }

    /**
     * Delete all attachments for this model.
     */
    public function deleteAllAttachments(bool $force = false): int
    {
        $service = app(\App\Services\Core\AttachmentService::class);
        $deleted = 0;

        foreach ($this->attachments as $attachment) {
            $service->delete($attachment, $force);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Boot the trait - clean up attachments when model is deleted.
     */
    protected static function bootHasAttachments(): void
    {
        static::deleting(function ($model) {
            // Only force delete attachments if model is being force deleted
            $force = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
            $model->deleteAllAttachments($force);
        });
    }
}
