<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'image_url',
        'level',
        'path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            if (!$category->slug) {
                $category->slug = Str::slug($category->name);
            }

            // Set level and path based on parent
            if ($category->parent_id) {
                $parent = self::find($category->parent_id);
                if ($parent) {
                    $category->level = $parent->level + 1;
                    $category->path = $parent->path . '.' . $category->id;
                }
            } else {
                $category->level = 1;
            }
        });

        static::created(function (self $category) {
            // Update path with actual ID after creation
            if ($category->parent_id) {
                $parent = $category->parent;
                $category->path = $parent->path . '.' . $category->id;
            } else {
                $category->path = (string) $category->id;
            }
            $category->saveQuietly();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all ancestor categories.
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($ancestors, $parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    /**
     * Get the full path as breadcrumb.
     */
    public function getBreadcrumb(): string
    {
        $parts = array_map(fn($a) => $a->name, $this->ancestors());
        $parts[] = $this->name;

        return implode(' > ', $parts);
    }

    /**
     * Get all descendant category IDs (including self).
     */
    public function getDescendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }
}
