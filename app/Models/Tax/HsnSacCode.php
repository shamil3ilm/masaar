<?php

declare(strict_types=1);

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Model;

class HsnSacCode extends Model
{
    public const TYPE_GOODS = 'goods';
    public const TYPE_SERVICE = 'service';

    protected $table = 'hsn_sac_codes';

    protected $fillable = [
        'code',
        'description',
        'gst_rate',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
        ];
    }

    /**
     * Check if this is for goods (HSN).
     */
    public function isHsn(): bool
    {
        return $this->type === self::TYPE_GOODS;
    }

    /**
     * Check if this is for services (SAC).
     */
    public function isSac(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    /**
     * Get the code type label.
     */
    public function getCodeTypeLabel(): string
    {
        return $this->isHsn() ? 'HSN' : 'SAC';
    }

    public function scopeGoods($query)
    {
        return $query->where('type', self::TYPE_GOODS);
    }

    public function scopeServices($query)
    {
        return $query->where('type', self::TYPE_SERVICE);
    }

    public function scopeByRate($query, float $rate)
    {
        return $query->where('gst_rate', $rate);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }
}
