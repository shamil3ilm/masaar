<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'document_type',
        'paper_size',
        'orientation',
        'template_content',
        'template_file',
        'settings',
        'sections',
        'show_logo',
        'show_qr_code',
        'show_signature',
        'show_watermark',
        'watermark_text',
        'primary_color',
        'secondary_color',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'sections' => 'array',
        'show_logo' => 'boolean',
        'show_qr_code' => 'boolean',
        'show_signature' => 'boolean',
        'show_watermark' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Document types
    public const DOC_INVOICE = 'invoice';
    public const DOC_QUOTATION = 'quotation';
    public const DOC_SALES_ORDER = 'sales_order';
    public const DOC_PURCHASE_ORDER = 'purchase_order';
    public const DOC_CREDIT_NOTE = 'credit_note';
    public const DOC_DEBIT_NOTE = 'debit_note';
    public const DOC_DELIVERY_NOTE = 'delivery_note';
    public const DOC_PAYMENT_RECEIPT = 'payment_receipt';
    public const DOC_BILL = 'bill';
    public const DOC_PAYSLIP = 'payslip';
    public const DOC_STATEMENT = 'statement';

    // Paper sizes with dimensions (mm)
    public const PAPER_SIZES = [
        'a3' => ['width' => 297, 'height' => 420, 'name' => 'A3'],
        'a4' => ['width' => 210, 'height' => 297, 'name' => 'A4'],
        'a5' => ['width' => 148, 'height' => 210, 'name' => 'A5'],
        'a6' => ['width' => 105, 'height' => 148, 'name' => 'A6'],
        'letter' => ['width' => 216, 'height' => 279, 'name' => 'Letter'],
        'legal' => ['width' => 216, 'height' => 356, 'name' => 'Legal'],
        'thermal_80' => ['width' => 80, 'height' => 0, 'name' => '80mm Thermal'],
        'thermal_58' => ['width' => 58, 'height' => 0, 'name' => '58mm Thermal'],
        'sunmi_v2' => ['width' => 58, 'height' => 0, 'name' => 'Sunmi V2 (58mm)'],
        'sunmi_v2_pro' => ['width' => 80, 'height' => 0, 'name' => 'Sunmi V2 Pro (80mm)'],
    ];

    // Default templates
    public const DEFAULT_TEMPLATES = [
        // A4 Templates (default for standard printing)
        [
            'code' => 'invoice_a4',
            'name' => 'Standard Invoice - A4',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.invoice-a4',
        ],
        [
            'code' => 'invoice_a4_detailed',
            'name' => 'Detailed Invoice - A4',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.invoice-a4-detailed',
        ],
        [
            'code' => 'quotation_a4',
            'name' => 'Standard Quotation - A4',
            'document_type' => self::DOC_QUOTATION,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.quotation-a4',
        ],
        [
            'code' => 'purchase_order_a4',
            'name' => 'Purchase Order - A4',
            'document_type' => self::DOC_PURCHASE_ORDER,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.purchase-order-a4',
        ],
        [
            'code' => 'credit_note_a4',
            'name' => 'Credit Note - A4',
            'document_type' => self::DOC_CREDIT_NOTE,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.credit-note-a4',
        ],
        [
            'code' => 'delivery_note_a4',
            'name' => 'Delivery Note - A4',
            'document_type' => self::DOC_DELIVERY_NOTE,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.delivery-note-a4',
        ],
        [
            'code' => 'payment_receipt_a4',
            'name' => 'Payment Receipt - A4',
            'document_type' => self::DOC_PAYMENT_RECEIPT,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.payment-receipt-a4',
        ],

        // A5 Templates (half-page)
        [
            'code' => 'invoice_a5',
            'name' => 'Compact Invoice - A5',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'a5',
            'template_file' => 'exports.templates.invoice-a5',
        ],
        [
            'code' => 'quotation_a5',
            'name' => 'Compact Quotation - A5',
            'document_type' => self::DOC_QUOTATION,
            'paper_size' => 'a5',
            'template_file' => 'exports.templates.quotation-a5',
        ],
        [
            'code' => 'delivery_note_a5',
            'name' => 'Compact Delivery Note - A5',
            'document_type' => self::DOC_DELIVERY_NOTE,
            'paper_size' => 'a5',
            'template_file' => 'exports.templates.delivery-note-a5',
        ],

        // A3 Templates (large format)
        [
            'code' => 'invoice_a3',
            'name' => 'Large Invoice - A3',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'a3',
            'template_file' => 'exports.templates.invoice-a3',
        ],
        [
            'code' => 'statement_a3',
            'name' => 'Account Statement - A3',
            'document_type' => self::DOC_STATEMENT,
            'paper_size' => 'a3',
            'template_file' => 'exports.templates.statement-a3',
        ],

        // 80mm Thermal Templates
        [
            'code' => 'invoice_thermal_80',
            'name' => 'Receipt - 80mm Thermal',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'thermal_80',
            'template_file' => 'exports.templates.invoice-thermal-80',
        ],
        [
            'code' => 'payment_receipt_thermal_80',
            'name' => 'Payment Receipt - 80mm Thermal',
            'document_type' => self::DOC_PAYMENT_RECEIPT,
            'paper_size' => 'thermal_80',
            'template_file' => 'exports.templates.payment-receipt-thermal-80',
        ],

        // 58mm Thermal Templates
        [
            'code' => 'invoice_thermal_58',
            'name' => 'Receipt - 58mm Thermal',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'thermal_58',
            'template_file' => 'exports.templates.invoice-thermal-58',
        ],

        // Sunmi V2 Templates (58mm)
        [
            'code' => 'invoice_sunmi_v2',
            'name' => 'Receipt - Sunmi V2',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'sunmi_v2',
            'template_file' => 'exports.templates.invoice-sunmi-v2',
        ],

        // Sunmi V2 Pro Templates (80mm)
        [
            'code' => 'invoice_sunmi_v2_pro',
            'name' => 'Receipt - Sunmi V2 Pro',
            'document_type' => self::DOC_INVOICE,
            'paper_size' => 'sunmi_v2_pro',
            'template_file' => 'exports.templates.invoice-sunmi-v2-pro',
        ],

        // Payslip Templates
        [
            'code' => 'payslip_a4',
            'name' => 'Payslip - A4',
            'document_type' => self::DOC_PAYSLIP,
            'paper_size' => 'a4',
            'template_file' => 'exports.templates.payslip-a4',
        ],
    ];

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDocumentType($query, string $documentType)
    {
        return $query->where('document_type', $documentType);
    }

    public function scopeForPaperSize($query, string $paperSize)
    {
        return $query->where('paper_size', $paperSize);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeThermal($query)
    {
        return $query->whereIn('paper_size', ['thermal_80', 'thermal_58', 'sunmi_v2', 'sunmi_v2_pro']);
    }

    // Helpers

    public function isThermal(): bool
    {
        return in_array($this->paper_size, ['thermal_80', 'thermal_58', 'sunmi_v2', 'sunmi_v2_pro']);
    }

    public function isSunmi(): bool
    {
        return in_array($this->paper_size, ['sunmi_v2', 'sunmi_v2_pro']);
    }

    public function getPaperDimensions(): array
    {
        return self::PAPER_SIZES[$this->paper_size] ?? self::PAPER_SIZES['a4'];
    }

    public function getViewPath(): string
    {
        if ($this->template_content) {
            return 'exports.custom';
        }

        return $this->template_file ?? 'exports.templates.invoice-a4';
    }

    public function getMergedSettings(): array
    {
        $defaults = $this->getDefaultSettings();
        return array_merge($defaults, $this->settings ?? []);
    }

    protected function getDefaultSettings(): array
    {
        if ($this->isThermal()) {
            return [
                'font_size' => 10,
                'line_height' => 1.2,
                'margin_top' => 3,
                'margin_right' => 2,
                'margin_bottom' => 3,
                'margin_left' => 2,
                'char_per_line' => $this->paper_size === 'thermal_58' ? 32 : 48,
            ];
        }

        $sizes = [
            'a3' => ['font_size' => 14, 'margin' => 20],
            'a4' => ['font_size' => 12, 'margin' => 15],
            'a5' => ['font_size' => 10, 'margin' => 10],
            'a6' => ['font_size' => 9, 'margin' => 8],
        ];

        $size = $sizes[$this->paper_size] ?? $sizes['a4'];

        return [
            'font_size' => $size['font_size'],
            'line_height' => 1.4,
            'margin_top' => $size['margin'],
            'margin_right' => $size['margin'],
            'margin_bottom' => $size['margin'],
            'margin_left' => $size['margin'],
        ];
    }

    public static function getDocumentTypes(): array
    {
        return [
            self::DOC_INVOICE => 'Invoice',
            self::DOC_QUOTATION => 'Quotation',
            self::DOC_SALES_ORDER => 'Sales Order',
            self::DOC_PURCHASE_ORDER => 'Purchase Order',
            self::DOC_CREDIT_NOTE => 'Credit Note',
            self::DOC_DEBIT_NOTE => 'Debit Note',
            self::DOC_DELIVERY_NOTE => 'Delivery Note',
            self::DOC_PAYMENT_RECEIPT => 'Payment Receipt',
            self::DOC_BILL => 'Bill',
            self::DOC_PAYSLIP => 'Payslip',
            self::DOC_STATEMENT => 'Statement',
        ];
    }

    public static function getPaperSizeOptions(): array
    {
        return collect(self::PAPER_SIZES)->mapWithKeys(function ($data, $key) {
            return [$key => $data['name']];
        })->toArray();
    }
}
