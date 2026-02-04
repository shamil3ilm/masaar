<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Attachment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'attachable_type',
        'attachable_id',
        'file_name',
        'original_name',
        'mime_type',
        'file_size',
        'disk',
        'path',
        'category',
        'description',
        'metadata',
        'is_public',
        'visibility',
        'expires_at',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata' => 'array',
        'is_public' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'path',
        'disk',
    ];

    // Categories
    public const CATEGORY_RECEIPT = 'receipt';
    public const CATEGORY_CONTRACT = 'contract';
    public const CATEGORY_IMAGE = 'image';
    public const CATEGORY_DOCUMENT = 'document';
    public const CATEGORY_SIGNATURE = 'signature';
    public const CATEGORY_LOGO = 'logo';
    public const CATEGORY_OTHER = 'other';

    // Visibility levels
    public const VISIBILITY_PRIVATE = 'private';     // Only uploader can access
    public const VISIBILITY_ORGANIZATION = 'organization'; // All org users can access
    public const VISIBILITY_PUBLIC = 'public';       // Anyone with link can access

    // Allowed MIME types by category
    public const ALLOWED_MIME_TYPES = [
        self::CATEGORY_IMAGE => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ],
        self::CATEGORY_DOCUMENT => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ],
        self::CATEGORY_RECEIPT => [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ],
    ];

    // Max file sizes in bytes
    public const MAX_FILE_SIZES = [
        self::CATEGORY_IMAGE => 5 * 1024 * 1024,     // 5MB
        self::CATEGORY_DOCUMENT => 20 * 1024 * 1024, // 20MB
        self::CATEGORY_RECEIPT => 10 * 1024 * 1024,  // 10MB
        'default' => 10 * 1024 * 1024,               // 10MB
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($attachment) {
            if (empty($attachment->uuid)) {
                $attachment->uuid = Str::uuid()->toString();
            }
        });

        static::deleting(function ($attachment) {
            // Delete actual file when model is deleted
            if (!$attachment->isForceDeleting()) {
                return; // Don't delete file on soft delete
            }
            $attachment->deleteFile();
        });
    }

    // Relationships

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AttachmentAccessLog::class);
    }

    // Scopes

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeImages($query)
    {
        return $query->where('category', self::CATEGORY_IMAGE);
    }

    public function scopeDocuments($query)
    {
        return $query->where('category', self::CATEGORY_DOCUMENT);
    }

    public function scopePublic($query)
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // URL generation

    public function getUrl(): string
    {
        if ($this->isPublic() && $this->disk === 's3') {
            return Storage::disk($this->disk)->url($this->path);
        }

        // Generate signed URL for private files
        return route('attachments.download', ['uuid' => $this->uuid]);
    }

    public function getTemporaryUrl(int $minutes = 60): string
    {
        if ($this->disk === 's3') {
            return Storage::disk($this->disk)->temporaryUrl(
                $this->path,
                now()->addMinutes($minutes)
            );
        }

        return route('attachments.download', [
            'uuid' => $this->uuid,
            'expires' => now()->addMinutes($minutes)->timestamp,
        ]);
    }

    public function getThumbnailUrl(?int $width = 200, ?int $height = 200): ?string
    {
        if (!$this->isImage()) {
            return null;
        }

        // Could integrate with image processing service
        return route('attachments.thumbnail', [
            'uuid' => $this->uuid,
            'w' => $width,
            'h' => $height,
        ]);
    }

    // File operations

    public function getContents(): string
    {
        return Storage::disk($this->disk)->get($this->path);
    }

    public function getStream()
    {
        return Storage::disk($this->disk)->readStream($this->path);
    }

    public function deleteFile(): bool
    {
        if (Storage::disk($this->disk)->exists($this->path)) {
            return Storage::disk($this->disk)->delete($this->path);
        }
        return true;
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function copyTo(string $newPath, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->disk;

        if ($disk === $this->disk) {
            return Storage::disk($this->disk)->copy($this->path, $newPath);
        }

        return Storage::disk($disk)->put(
            $newPath,
            Storage::disk($this->disk)->get($this->path)
        );
    }

    // Type checks

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isDocument(): bool
    {
        return in_array($this->mime_type, self::ALLOWED_MIME_TYPES[self::CATEGORY_DOCUMENT] ?? []);
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC || $this->is_public;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    // Access control

    public function canAccess(?User $user): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        if ($this->isPublic()) {
            return true;
        }

        if (!$user) {
            return false;
        }

        if ($this->visibility === self::VISIBILITY_PRIVATE) {
            return $user->id === $this->uploaded_by;
        }

        if ($this->visibility === self::VISIBILITY_ORGANIZATION) {
            return $user->organization_id === $this->organization_id;
        }

        return false;
    }

    public function logAccess(string $action, ?User $user = null): void
    {
        $this->accessLogs()->create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    // Helpers

    public function getFileSizeForHumans(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getExtension(): string
    {
        return pathinfo($this->original_name, PATHINFO_EXTENSION);
    }

    public function getIcon(): string
    {
        if ($this->isImage()) {
            return 'image';
        }
        if ($this->isPdf()) {
            return 'file-pdf';
        }
        if (str_contains($this->mime_type, 'word')) {
            return 'file-word';
        }
        if (str_contains($this->mime_type, 'excel') || str_contains($this->mime_type, 'spreadsheet')) {
            return 'file-excel';
        }
        return 'file';
    }

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_RECEIPT => 'Receipt',
            self::CATEGORY_CONTRACT => 'Contract',
            self::CATEGORY_IMAGE => 'Image',
            self::CATEGORY_DOCUMENT => 'Document',
            self::CATEGORY_SIGNATURE => 'Signature',
            self::CATEGORY_LOGO => 'Logo',
            self::CATEGORY_OTHER => 'Other',
        ];
    }
}
