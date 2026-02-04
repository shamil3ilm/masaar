<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Support\Facades\Config;

class PrintConfigService
{
    /**
     * Available paper sizes with dimensions in mm.
     */
    public const PAPER_SIZES = [
        'a4' => ['width' => 210, 'height' => 297, 'name' => 'A4'],
        'a5' => ['width' => 148, 'height' => 210, 'name' => 'A5'],
        'letter' => ['width' => 216, 'height' => 279, 'name' => 'US Letter'],
        'legal' => ['width' => 216, 'height' => 356, 'name' => 'US Legal'],
        'thermal_80mm' => ['width' => 80, 'height' => 297, 'name' => 'Thermal 80mm'],
        'thermal_58mm' => ['width' => 58, 'height' => 297, 'name' => 'Thermal 58mm'],
        'custom' => ['width' => null, 'height' => null, 'name' => 'Custom'],
    ];

    /**
     * Document types with their default configurations.
     */
    public const DOCUMENT_CONFIGS = [
        'invoice' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.invoice',
            'show_logo' => true,
            'show_qr_code' => true,
            'show_terms' => true,
            'copies' => 1,
            'footer_text' => 'Thank you for your business!',
        ],
        'invoice_simplified' => [
            'paper' => 'thermal_80mm',
            'orientation' => 'portrait',
            'template' => 'exports.invoice-thermal',
            'show_logo' => false,
            'show_qr_code' => true,
            'show_terms' => false,
            'copies' => 1,
            'footer_text' => null,
        ],
        'quotation' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.quotation',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => true,
            'copies' => 1,
            'footer_text' => 'This quotation is valid for 30 days.',
        ],
        'sales_order' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.sales-order',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => true,
            'copies' => 1,
            'footer_text' => null,
        ],
        'purchase_order' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.purchase-order',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => true,
            'copies' => 2,
            'footer_text' => null,
        ],
        'bill' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.bill',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 1,
            'footer_text' => null,
        ],
        'delivery_note' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.delivery-note',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 2,
            'footer_text' => 'Goods received in good condition.',
        ],
        'payment_receipt' => [
            'paper' => 'a5',
            'orientation' => 'portrait',
            'template' => 'exports.payment-receipt',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 2,
            'footer_text' => null,
        ],
        'payslip' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.payslip',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 1,
            'footer_text' => 'This is a computer-generated document.',
        ],
        'work_order' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.work-order',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 2,
            'footer_text' => null,
        ],
        'stock_transfer' => [
            'paper' => 'a4',
            'orientation' => 'portrait',
            'template' => 'exports.stock-transfer',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 2,
            'footer_text' => null,
        ],
        'report_landscape' => [
            'paper' => 'a4',
            'orientation' => 'landscape',
            'template' => 'exports.report',
            'show_logo' => true,
            'show_qr_code' => false,
            'show_terms' => false,
            'copies' => 1,
            'footer_text' => null,
        ],
    ];

    /**
     * Get available paper sizes.
     */
    public function getPaperSizes(): array
    {
        return self::PAPER_SIZES;
    }

    /**
     * Get paper size configuration.
     */
    public function getPaperSize(string $size): array
    {
        return self::PAPER_SIZES[$size] ?? self::PAPER_SIZES['a4'];
    }

    /**
     * Get document configuration.
     */
    public function getDocumentConfig(string $documentType): array
    {
        $defaultConfig = self::DOCUMENT_CONFIGS[$documentType] ?? self::DOCUMENT_CONFIGS['invoice'];

        // Merge with organization-specific settings if available
        $orgConfig = $this->getOrganizationPrintSettings($documentType);

        return array_merge($defaultConfig, $orgConfig);
    }

    /**
     * Get organization-specific print settings.
     */
    protected function getOrganizationPrintSettings(string $documentType): array
    {
        if (!auth()->check()) {
            return [];
        }

        $organization = auth()->user()->organization;

        if (!$organization) {
            return [];
        }

        // Get from organization settings (stored as JSON)
        $settings = $organization->settings ?? [];
        $printSettings = $settings['print'] ?? [];

        return $printSettings[$documentType] ?? [];
    }

    /**
     * Get PDF options for DomPDF.
     */
    public function getPdfOptions(string $documentType, array $overrides = []): array
    {
        $config = $this->getDocumentConfig($documentType);
        $paperSize = $this->getPaperSize($config['paper']);

        $options = [
            'paper' => $config['paper'] === 'custom' ? [$paperSize['width'], $paperSize['height']] : $config['paper'],
            'orientation' => $config['orientation'],
            'dpi' => 150,
            'defaultFont' => 'sans-serif',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'isFontSubsettingEnabled' => true,
        ];

        // Handle thermal paper
        if (str_starts_with($config['paper'], 'thermal_')) {
            $options['paper'] = [$paperSize['width'], $paperSize['height']];
            $options['dpi'] = 203; // Standard thermal printer DPI
        }

        return array_merge($options, $overrides);
    }

    /**
     * Get template path for document type.
     */
    public function getTemplate(string $documentType): string
    {
        $config = $this->getDocumentConfig($documentType);

        return $config['template'];
    }

    /**
     * Check if document type supports QR code.
     */
    public function supportsQrCode(string $documentType): bool
    {
        $config = $this->getDocumentConfig($documentType);

        return $config['show_qr_code'] ?? false;
    }

    /**
     * Get number of copies to print.
     */
    public function getCopies(string $documentType): int
    {
        $config = $this->getDocumentConfig($documentType);

        return $config['copies'] ?? 1;
    }

    /**
     * Build print data with all necessary information.
     */
    public function buildPrintData(string $documentType, array $documentData, array $options = []): array
    {
        $config = $this->getDocumentConfig($documentType);
        $organization = auth()->user()?->organization;

        return [
            'document' => $documentData,
            'config' => $config,
            'options' => array_merge($this->getPdfOptions($documentType), $options),
            'organization' => $organization ? [
                'name' => $organization->legal_name ?? $organization->name,
                'logo' => $config['show_logo'] ? $organization->logo_url : null,
                'address' => $this->formatOrganizationAddress($organization),
                'tax_number' => $organization->tax_number,
                'phone' => $organization->phone,
                'email' => $organization->email,
                'website' => $organization->website,
            ] : null,
            'footer_text' => $config['footer_text'],
            'printed_at' => now()->format('Y-m-d H:i:s'),
            'printed_by' => auth()->user()?->name,
        ];
    }

    /**
     * Format organization address.
     */
    protected function formatOrganizationAddress($organization): string
    {
        $parts = array_filter([
            $organization->address_line_1 ?? null,
            $organization->address_line_2 ?? null,
            $organization->city ?? null,
            $organization->state ?? null,
            $organization->postal_code ?? null,
            $organization->country_code ?? null,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get margin settings for paper type.
     */
    public function getMargins(string $paperType): array
    {
        $defaults = [
            'top' => 10,
            'right' => 10,
            'bottom' => 10,
            'left' => 10,
        ];

        // Thermal paper has smaller margins
        if (str_starts_with($paperType, 'thermal_')) {
            return [
                'top' => 2,
                'right' => 2,
                'bottom' => 2,
                'left' => 2,
            ];
        }

        // A5 slightly smaller margins
        if ($paperType === 'a5') {
            return [
                'top' => 8,
                'right' => 8,
                'bottom' => 8,
                'left' => 8,
            ];
        }

        return $defaults;
    }
}
