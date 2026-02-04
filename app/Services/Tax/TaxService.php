<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\Core\Organization;
use App\Models\Inventory\Product;
use App\Models\Sales\Contact;
use Illuminate\Support\Collection;

class TaxService
{
    private array $regionalConfig;

    public function __construct()
    {
        $this->regionalConfig = config('regional', []);
    }

    /**
     * Calculate tax for a line item.
     */
    public function calculateLineTax(
        string $amount,
        string $taxRate,
        bool $isTaxInclusive = false,
        int $decimals = 4
    ): TaxCalculation {
        if ($isTaxInclusive) {
            return $this->extractTaxFromInclusive($amount, $taxRate, $decimals);
        }

        return $this->calculateTaxOnExclusive($amount, $taxRate, $decimals);
    }

    /**
     * Calculate tax on tax-exclusive amount.
     */
    public function calculateTaxOnExclusive(string $amount, string $taxRate, int $decimals = 4): TaxCalculation
    {
        $taxAmount = bcmul($amount, bcdiv($taxRate, '100', 10), $decimals);
        $totalAmount = bcadd($amount, $taxAmount, $decimals);

        return new TaxCalculation(
            taxableAmount: $amount,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            taxRate: $taxRate,
            isTaxInclusive: false
        );
    }

    /**
     * Extract tax from tax-inclusive amount.
     */
    public function extractTaxFromInclusive(string $amount, string $taxRate, int $decimals = 4): TaxCalculation
    {
        // Formula: taxable = inclusive / (1 + rate/100)
        $divisor = bcadd('1', bcdiv($taxRate, '100', 10), 10);
        $taxableAmount = bcdiv($amount, $divisor, $decimals);
        $taxAmount = bcsub($amount, $taxableAmount, $decimals);

        return new TaxCalculation(
            taxableAmount: $taxableAmount,
            taxAmount: $taxAmount,
            totalAmount: $amount,
            taxRate: $taxRate,
            isTaxInclusive: true
        );
    }

    /**
     * Calculate VAT for GCC countries.
     */
    public function calculateVat(
        string $countryCode,
        string $amount,
        string $taxCategoryCode,
        bool $isTaxInclusive = false,
        bool $isB2B = false,
        ?string $customerTaxNumber = null
    ): VatCalculation {
        $config = $this->regionalConfig[$countryCode] ?? [];
        $taxCategories = $config['tax_categories'] ?? [];

        $category = $taxCategories[$taxCategoryCode] ?? null;
        $taxRate = $category['rate'] ?? $config['tax_rates']['standard'] ?? '0';

        // Check if customer is tax exempt (e.g., diplomat, government)
        if ($customerTaxNumber && $this->isExemptCustomer($customerTaxNumber, $countryCode)) {
            $taxRate = '0';
            $taxCategoryCode = 'E';
        }

        $baseTax = $this->calculateLineTax($amount, (string) $taxRate, $isTaxInclusive);

        return new VatCalculation(
            taxableAmount: $baseTax->taxableAmount,
            taxAmount: $baseTax->taxAmount,
            totalAmount: $baseTax->totalAmount,
            taxRate: (string) $taxRate,
            taxCategoryCode: $taxCategoryCode,
            countryCode: $countryCode,
            isB2B: $isB2B,
            isTaxInclusive: $isTaxInclusive
        );
    }

    /**
     * Calculate GST for India.
     */
    public function calculateGst(
        string $amount,
        string $gstRate,
        string $sellerStateCode,
        string $buyerStateCode,
        bool $isTaxInclusive = false,
        ?string $hsnCode = null,
        bool $isUnionTerritory = false
    ): GstCalculation {
        $isInterState = $sellerStateCode !== $buyerStateCode;

        $baseTax = $this->calculateLineTax($amount, $gstRate, $isTaxInclusive);
        $taxableAmount = $baseTax->taxableAmount;
        $totalTax = $baseTax->taxAmount;

        if ($isInterState) {
            // IGST (Integrated GST) - full rate applies
            return new GstCalculation(
                taxableAmount: $taxableAmount,
                igstRate: $gstRate,
                igstAmount: $totalTax,
                cgstRate: '0',
                cgstAmount: '0',
                sgstRate: '0',
                sgstAmount: '0',
                utgstRate: '0',
                utgstAmount: '0',
                totalTax: $totalTax,
                totalAmount: $baseTax->totalAmount,
                isInterState: true,
                sellerStateCode: $sellerStateCode,
                buyerStateCode: $buyerStateCode,
                hsnCode: $hsnCode,
                isTaxInclusive: $isTaxInclusive
            );
        }

        // Intra-state: CGST + SGST (or UTGST for Union Territories)
        $halfRate = bcdiv($gstRate, '2', 4);
        $halfTax = bcdiv($totalTax, '2', 4);

        // Handle rounding - ensure CGST + SGST = total
        $cgstAmount = $halfTax;
        $sgstAmount = bcsub($totalTax, $cgstAmount, 4);

        return new GstCalculation(
            taxableAmount: $taxableAmount,
            igstRate: '0',
            igstAmount: '0',
            cgstRate: $halfRate,
            cgstAmount: $cgstAmount,
            sgstRate: $isUnionTerritory ? '0' : $halfRate,
            sgstAmount: $isUnionTerritory ? '0' : $sgstAmount,
            utgstRate: $isUnionTerritory ? $halfRate : '0',
            utgstAmount: $isUnionTerritory ? $sgstAmount : '0',
            totalTax: $totalTax,
            totalAmount: $baseTax->totalAmount,
            isInterState: false,
            sellerStateCode: $sellerStateCode,
            buyerStateCode: $buyerStateCode,
            hsnCode: $hsnCode,
            isTaxInclusive: $isTaxInclusive
        );
    }

    /**
     * Calculate tax for invoice lines.
     */
    public function calculateInvoiceTax(
        Organization $organization,
        array $lines,
        ?Contact $customer = null,
        ?string $placeOfSupply = null
    ): InvoiceTaxResult {
        $countryCode = $organization->country_code;
        $taxScheme = $organization->tax_scheme;
        $isTaxInclusive = false; // Can be determined from price list

        $lineTaxes = [];
        $taxSummary = [];
        $totalTaxable = '0';
        $totalTax = '0';
        $totalAmount = '0';

        // GST-specific totals
        $totalCgst = '0';
        $totalSgst = '0';
        $totalIgst = '0';
        $totalUtgst = '0';

        foreach ($lines as $index => $line) {
            $lineAmount = $line['amount'] ?? bcmul($line['quantity'] ?? '1', $line['unit_price'] ?? '0', 4);
            $taxRate = $line['tax_rate'] ?? '0';
            $taxCategoryCode = $line['tax_category'] ?? 'S';
            $hsnCode = $line['hsn_code'] ?? null;

            if ($taxScheme === 'GST' && $countryCode === 'IN') {
                $sellerState = substr($organization->tax_number ?? '', 0, 2);
                $buyerState = $placeOfSupply ?? substr($customer?->tax_number ?? '', 0, 2) ?? $sellerState;

                $gstCalc = $this->calculateGst(
                    $lineAmount,
                    $taxRate,
                    $sellerState,
                    $buyerState,
                    $isTaxInclusive,
                    $hsnCode
                );

                $lineTaxes[$index] = [
                    'line_index' => $index,
                    'taxable_amount' => $gstCalc->taxableAmount,
                    'tax_amount' => $gstCalc->totalTax,
                    'total_amount' => $gstCalc->totalAmount,
                    'tax_rate' => $taxRate,
                    'cgst_rate' => $gstCalc->cgstRate,
                    'cgst_amount' => $gstCalc->cgstAmount,
                    'sgst_rate' => $gstCalc->sgstRate,
                    'sgst_amount' => $gstCalc->sgstAmount,
                    'igst_rate' => $gstCalc->igstRate,
                    'igst_amount' => $gstCalc->igstAmount,
                    'hsn_code' => $hsnCode,
                    'is_inter_state' => $gstCalc->isInterState,
                ];

                $totalTaxable = bcadd($totalTaxable, $gstCalc->taxableAmount, 4);
                $totalTax = bcadd($totalTax, $gstCalc->totalTax, 4);
                $totalAmount = bcadd($totalAmount, $gstCalc->totalAmount, 4);
                $totalCgst = bcadd($totalCgst, $gstCalc->cgstAmount, 4);
                $totalSgst = bcadd($totalSgst, $gstCalc->sgstAmount, 4);
                $totalIgst = bcadd($totalIgst, $gstCalc->igstAmount, 4);

                // Group by HSN for GST reporting
                $hsnKey = $hsnCode ?? 'NO_HSN';
                if (!isset($taxSummary[$hsnKey])) {
                    $taxSummary[$hsnKey] = [
                        'hsn_code' => $hsnCode,
                        'taxable_amount' => '0',
                        'cgst_amount' => '0',
                        'sgst_amount' => '0',
                        'igst_amount' => '0',
                        'total_tax' => '0',
                    ];
                }
                $taxSummary[$hsnKey]['taxable_amount'] = bcadd($taxSummary[$hsnKey]['taxable_amount'], $gstCalc->taxableAmount, 4);
                $taxSummary[$hsnKey]['cgst_amount'] = bcadd($taxSummary[$hsnKey]['cgst_amount'], $gstCalc->cgstAmount, 4);
                $taxSummary[$hsnKey]['sgst_amount'] = bcadd($taxSummary[$hsnKey]['sgst_amount'], $gstCalc->sgstAmount, 4);
                $taxSummary[$hsnKey]['igst_amount'] = bcadd($taxSummary[$hsnKey]['igst_amount'], $gstCalc->igstAmount, 4);
                $taxSummary[$hsnKey]['total_tax'] = bcadd($taxSummary[$hsnKey]['total_tax'], $gstCalc->totalTax, 4);
            } else {
                // VAT calculation
                $vatCalc = $this->calculateVat(
                    $countryCode,
                    $lineAmount,
                    $taxCategoryCode,
                    $isTaxInclusive,
                    $customer?->tax_number !== null,
                    $customer?->tax_number
                );

                $lineTaxes[$index] = [
                    'line_index' => $index,
                    'taxable_amount' => $vatCalc->taxableAmount,
                    'tax_amount' => $vatCalc->taxAmount,
                    'total_amount' => $vatCalc->totalAmount,
                    'tax_rate' => $vatCalc->taxRate,
                    'tax_category' => $vatCalc->taxCategoryCode,
                ];

                $totalTaxable = bcadd($totalTaxable, $vatCalc->taxableAmount, 4);
                $totalTax = bcadd($totalTax, $vatCalc->taxAmount, 4);
                $totalAmount = bcadd($totalAmount, $vatCalc->totalAmount, 4);

                // Group by tax category for VAT reporting
                $catKey = $taxCategoryCode . '_' . $taxRate;
                if (!isset($taxSummary[$catKey])) {
                    $taxSummary[$catKey] = [
                        'tax_category' => $taxCategoryCode,
                        'tax_rate' => $taxRate,
                        'taxable_amount' => '0',
                        'tax_amount' => '0',
                    ];
                }
                $taxSummary[$catKey]['taxable_amount'] = bcadd($taxSummary[$catKey]['taxable_amount'], $vatCalc->taxableAmount, 4);
                $taxSummary[$catKey]['tax_amount'] = bcadd($taxSummary[$catKey]['tax_amount'], $vatCalc->taxAmount, 4);
            }
        }

        return new InvoiceTaxResult(
            lineTaxes: $lineTaxes,
            taxSummary: array_values($taxSummary),
            totalTaxable: $totalTaxable,
            totalTax: $totalTax,
            totalAmount: $totalAmount,
            totalCgst: $totalCgst,
            totalSgst: $totalSgst,
            totalIgst: $totalIgst,
            totalUtgst: $totalUtgst,
            taxScheme: $taxScheme,
            countryCode: $countryCode
        );
    }

    /**
     * Get tax rate for a product based on organization's country.
     */
    public function getProductTaxRate(Product $product, Organization $organization): string
    {
        $countryCode = $organization->country_code;
        $config = $this->regionalConfig[$countryCode] ?? [];

        // For India GST, use HSN-based rate
        if ($organization->tax_scheme === 'GST' && $product->hsn_code) {
            // Would look up from HSN code table
            return (string) ($product->tax_rate ?? $config['tax_rates']['rate_18'] ?? '18');
        }

        // For VAT, use tax category
        $taxCategory = $product->tax_category_id
            ? ($product->taxCategory->code ?? 'S')
            : 'S';

        return (string) ($config['tax_categories'][$taxCategory]['rate'] ?? $config['tax_rates']['standard'] ?? '0');
    }

    /**
     * Check if a customer is tax exempt.
     */
    protected function isExemptCustomer(?string $taxNumber, string $countryCode): bool
    {
        // Implement based on regional rules
        // e.g., diplomatic missions, certain government entities
        return false;
    }

    /**
     * Convert tax-inclusive price to tax-exclusive.
     */
    public function toExclusive(string $inclusivePrice, string $taxRate): string
    {
        $calc = $this->extractTaxFromInclusive($inclusivePrice, $taxRate);
        return $calc->taxableAmount;
    }

    /**
     * Convert tax-exclusive price to tax-inclusive.
     */
    public function toInclusive(string $exclusivePrice, string $taxRate): string
    {
        $calc = $this->calculateTaxOnExclusive($exclusivePrice, $taxRate);
        return $calc->totalAmount;
    }

    /**
     * Round tax amount according to regional rules.
     */
    public function roundTax(string $amount, string $countryCode): string
    {
        $config = $this->regionalConfig[$countryCode] ?? $this->regionalConfig['defaults'] ?? [];
        $precision = $config['decimal_places']['currency'] ?? 2;
        $method = $config['rounding_method'] ?? 'half_up';

        return match ($method) {
            'half_down' => $this->roundHalfDown($amount, $precision),
            'floor' => bcadd($amount, '0', $precision), // PHP bcadd truncates
            'ceil' => $this->roundCeil($amount, $precision),
            default => $this->roundHalfUp($amount, $precision),
        };
    }

    protected function roundHalfUp(string $amount, int $precision): string
    {
        $factor = bcpow('10', (string) $precision);
        $temp = bcmul($amount, $factor, $precision + 1);
        $temp = bcadd($temp, '0.5', 0);
        return bcdiv($temp, $factor, $precision);
    }

    protected function roundHalfDown(string $amount, int $precision): string
    {
        $factor = bcpow('10', (string) $precision);
        $temp = bcmul($amount, $factor, $precision + 1);
        $temp = bcsub(bcadd($temp, '0.5', 0), '0.0001', 0);
        return bcdiv($temp, $factor, $precision);
    }

    protected function roundCeil(string $amount, int $precision): string
    {
        $factor = bcpow('10', (string) $precision);
        $temp = bcmul($amount, $factor, $precision + 1);
        $temp = bcadd($temp, '0.9999', 0);
        return bcdiv($temp, $factor, $precision);
    }
}

// Data classes for tax calculations

class TaxCalculation
{
    public function __construct(
        public readonly string $taxableAmount,
        public readonly string $taxAmount,
        public readonly string $totalAmount,
        public readonly string $taxRate,
        public readonly bool $isTaxInclusive
    ) {}
}

class VatCalculation
{
    public function __construct(
        public readonly string $taxableAmount,
        public readonly string $taxAmount,
        public readonly string $totalAmount,
        public readonly string $taxRate,
        public readonly string $taxCategoryCode,
        public readonly string $countryCode,
        public readonly bool $isB2B,
        public readonly bool $isTaxInclusive
    ) {}
}

class GstCalculation
{
    public function __construct(
        public readonly string $taxableAmount,
        public readonly string $igstRate,
        public readonly string $igstAmount,
        public readonly string $cgstRate,
        public readonly string $cgstAmount,
        public readonly string $sgstRate,
        public readonly string $sgstAmount,
        public readonly string $utgstRate,
        public readonly string $utgstAmount,
        public readonly string $totalTax,
        public readonly string $totalAmount,
        public readonly bool $isInterState,
        public readonly string $sellerStateCode,
        public readonly string $buyerStateCode,
        public readonly ?string $hsnCode,
        public readonly bool $isTaxInclusive
    ) {}
}

class InvoiceTaxResult
{
    public function __construct(
        public readonly array $lineTaxes,
        public readonly array $taxSummary,
        public readonly string $totalTaxable,
        public readonly string $totalTax,
        public readonly string $totalAmount,
        public readonly string $totalCgst,
        public readonly string $totalSgst,
        public readonly string $totalIgst,
        public readonly string $totalUtgst,
        public readonly string $taxScheme,
        public readonly string $countryCode
    ) {}
}
