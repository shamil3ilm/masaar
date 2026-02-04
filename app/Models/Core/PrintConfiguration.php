<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintConfiguration extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'printer_type',
        'default_paper_size',
        'paper_sizes',
        'thermal_settings',
        'margin_settings',
        'font_settings',
        'auto_cut',
        'open_drawer',
        'copies',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'paper_sizes' => 'array',
        'thermal_settings' => 'array',
        'margin_settings' => 'array',
        'font_settings' => 'array',
        'auto_cut' => 'boolean',
        'open_drawer' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Printer types
    public const PRINTER_LASER = 'laser';
    public const PRINTER_INKJET = 'inkjet';
    public const PRINTER_THERMAL_80 = 'thermal_80';
    public const PRINTER_THERMAL_58 = 'thermal_58';
    public const PRINTER_SUNMI_V2 = 'sunmi_v2';
    public const PRINTER_SUNMI_V2_PRO = 'sunmi_v2_pro';
    public const PRINTER_SUNMI_V2S = 'sunmi_v2s';
    public const PRINTER_SUNMI_T2 = 'sunmi_t2';
    public const PRINTER_SUNMI_T2_MINI = 'sunmi_t2_mini';
    public const PRINTER_EPSON_TM = 'epson_tm';
    public const PRINTER_STAR_TSP = 'star_tsp';

    // Thermal printer configurations
    public const THERMAL_CONFIGS = [
        self::PRINTER_SUNMI_V2 => [
            'width' => 58,
            'dpi' => 203,
            'max_chars_per_line' => 32,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 384,
        ],
        self::PRINTER_SUNMI_V2_PRO => [
            'width' => 80,
            'dpi' => 203,
            'max_chars_per_line' => 48,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 576,
        ],
        self::PRINTER_SUNMI_V2S => [
            'width' => 58,
            'dpi' => 203,
            'max_chars_per_line' => 32,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 384,
        ],
        self::PRINTER_SUNMI_T2 => [
            'width' => 80,
            'dpi' => 203,
            'max_chars_per_line' => 48,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 576,
        ],
        self::PRINTER_SUNMI_T2_MINI => [
            'width' => 58,
            'dpi' => 203,
            'max_chars_per_line' => 32,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 384,
        ],
        self::PRINTER_THERMAL_80 => [
            'width' => 80,
            'dpi' => 203,
            'max_chars_per_line' => 48,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 576,
        ],
        self::PRINTER_THERMAL_58 => [
            'width' => 58,
            'dpi' => 203,
            'max_chars_per_line' => 32,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 384,
        ],
        self::PRINTER_EPSON_TM => [
            'width' => 80,
            'dpi' => 180,
            'max_chars_per_line' => 42,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 512,
        ],
        self::PRINTER_STAR_TSP => [
            'width' => 80,
            'dpi' => 203,
            'max_chars_per_line' => 48,
            'supports_qr' => true,
            'supports_barcode' => true,
            'supports_image' => true,
            'paper_width_dots' => 576,
        ],
    ];

    // Relationships

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForBranch($query, ?int $branchId)
    {
        if ($branchId) {
            return $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });
        }

        return $query->whereNull('branch_id');
    }

    public function scopeThermal($query)
    {
        return $query->whereIn('printer_type', [
            self::PRINTER_THERMAL_80,
            self::PRINTER_THERMAL_58,
            self::PRINTER_SUNMI_V2,
            self::PRINTER_SUNMI_V2_PRO,
            self::PRINTER_SUNMI_V2S,
            self::PRINTER_SUNMI_T2,
            self::PRINTER_SUNMI_T2_MINI,
            self::PRINTER_EPSON_TM,
            self::PRINTER_STAR_TSP,
        ]);
    }

    // Helpers

    public function isThermal(): bool
    {
        return isset(self::THERMAL_CONFIGS[$this->printer_type]);
    }

    public function isSunmi(): bool
    {
        return in_array($this->printer_type, [
            self::PRINTER_SUNMI_V2,
            self::PRINTER_SUNMI_V2_PRO,
            self::PRINTER_SUNMI_V2S,
            self::PRINTER_SUNMI_T2,
            self::PRINTER_SUNMI_T2_MINI,
        ]);
    }

    public function getThermalConfig(): ?array
    {
        return self::THERMAL_CONFIGS[$this->printer_type] ?? null;
    }

    public function getMaxCharsPerLine(): int
    {
        $config = $this->getThermalConfig();
        return $config['max_chars_per_line'] ?? 48;
    }

    public function getPaperWidth(): int
    {
        $config = $this->getThermalConfig();
        return $config['width'] ?? 80;
    }

    public function getPaperWidthDots(): int
    {
        $config = $this->getThermalConfig();
        return $config['paper_width_dots'] ?? 576;
    }

    public function getMergedMargins(): array
    {
        $defaults = $this->isThermal()
            ? ['top' => 3, 'right' => 2, 'bottom' => 3, 'left' => 2]
            : ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15];

        return array_merge($defaults, $this->margin_settings ?? []);
    }

    public function getMergedFontSettings(): array
    {
        $defaults = $this->isThermal()
            ? ['family' => 'monospace', 'size' => 10, 'line_height' => 1.2]
            : ['family' => 'sans-serif', 'size' => 12, 'line_height' => 1.4];

        return array_merge($defaults, $this->font_settings ?? []);
    }

    public static function getPrinterTypes(): array
    {
        return [
            self::PRINTER_LASER => 'Laser Printer',
            self::PRINTER_INKJET => 'Inkjet Printer',
            self::PRINTER_THERMAL_80 => '80mm Thermal Printer',
            self::PRINTER_THERMAL_58 => '58mm Thermal Printer',
            self::PRINTER_SUNMI_V2 => 'Sunmi V2 (58mm)',
            self::PRINTER_SUNMI_V2_PRO => 'Sunmi V2 Pro (80mm)',
            self::PRINTER_SUNMI_V2S => 'Sunmi V2s (58mm)',
            self::PRINTER_SUNMI_T2 => 'Sunmi T2 (80mm)',
            self::PRINTER_SUNMI_T2_MINI => 'Sunmi T2 Mini (58mm)',
            self::PRINTER_EPSON_TM => 'Epson TM Series (80mm)',
            self::PRINTER_STAR_TSP => 'Star TSP Series (80mm)',
        ];
    }
}
