<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'barcode',
        'barcode_type',
        'is_primary',
        'quantity',
        'unit_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'quantity' => 'decimal:4',
    ];

    // Barcode types
    public const TYPE_EAN13 = 'EAN13';
    public const TYPE_EAN8 = 'EAN8';
    public const TYPE_UPC_A = 'UPC-A';
    public const TYPE_UPC_E = 'UPC-E';
    public const TYPE_CODE128 = 'CODE128';
    public const TYPE_CODE39 = 'CODE39';
    public const TYPE_QR = 'QR';
    public const TYPE_DATAMATRIX = 'DATAMATRIX';
    public const TYPE_INTERNAL = 'INTERNAL';

    // Relationships

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    // Scopes

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    // Helpers

    public function isPrimary(): bool
    {
        return $this->is_primary;
    }

    public function isPackBarcode(): bool
    {
        return (float) $this->quantity > 1;
    }

    /**
     * Validate barcode format.
     */
    public static function validateBarcode(string $barcode, string $type): bool
    {
        return match ($type) {
            self::TYPE_EAN13 => self::validateEan13($barcode),
            self::TYPE_EAN8 => self::validateEan8($barcode),
            self::TYPE_UPC_A => self::validateUpcA($barcode),
            default => strlen($barcode) > 0,
        };
    }

    protected static function validateEan13(string $barcode): bool
    {
        if (!preg_match('/^\d{13}$/', $barcode)) {
            return false;
        }

        return self::calculateEanCheckDigit(substr($barcode, 0, 12)) === (int) $barcode[12];
    }

    protected static function validateEan8(string $barcode): bool
    {
        if (!preg_match('/^\d{8}$/', $barcode)) {
            return false;
        }

        return self::calculateEan8CheckDigit(substr($barcode, 0, 7)) === (int) $barcode[7];
    }

    protected static function validateUpcA(string $barcode): bool
    {
        if (!preg_match('/^\d{12}$/', $barcode)) {
            return false;
        }

        return self::calculateUpcCheckDigit(substr($barcode, 0, 11)) === (int) $barcode[11];
    }

    /**
     * Calculate EAN-13 check digit.
     */
    public static function calculateEanCheckDigit(string $digits): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Calculate EAN-8 check digit.
     */
    public static function calculateEan8CheckDigit(string $digits): int
    {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Calculate UPC-A check digit.
     */
    public static function calculateUpcCheckDigit(string $digits): int
    {
        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 11; $i++) {
            if ($i % 2 === 0) {
                $odd += (int) $digits[$i];
            } else {
                $even += (int) $digits[$i];
            }
        }
        return (10 - (($odd * 3 + $even) % 10)) % 10;
    }

    /**
     * Generate internal barcode.
     */
    public static function generateInternalBarcode(int $organizationId, int $productId): string
    {
        $prefix = '20'; // Internal barcode prefix
        $orgPart = str_pad((string) ($organizationId % 10000), 4, '0', STR_PAD_LEFT);
        $productPart = str_pad((string) ($productId % 100000), 5, '0', STR_PAD_LEFT);
        $digits = $prefix . $orgPart . $productPart;
        $checkDigit = self::calculateEanCheckDigit($digits);

        return $digits . $checkDigit;
    }

    /**
     * Find product by barcode.
     */
    public static function findByBarcode(string $barcode, int $organizationId): ?self
    {
        return static::where('barcode', $barcode)
            ->whereHas('product', fn($q) => $q->where('organization_id', $organizationId))
            ->with(['product', 'variant', 'unit'])
            ->first();
    }
}
