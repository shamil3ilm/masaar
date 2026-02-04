<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\Core\Organization;
use App\Models\Tax\TaxCategory;
use App\Models\Tax\TaxRate;

class TaxCalculatorService
{
    /**
     * Calculate taxes for document lines.
     */
    public function calculate(
        Organization $organization,
        array $lines,
        ?string $placeOfSupply = null
    ): TaxResult {
        return match ($organization->tax_scheme) {
            'VAT' => $this->calculateVat($organization, $lines),
            'GST' => $this->calculateGst($organization, $lines, $placeOfSupply),
            default => $this->noTax($lines),
        };
    }

    /**
     * Calculate VAT for GCC countries.
     */
    protected function calculateVat(Organization $organization, array $lines): TaxResult
    {
        $result = new TaxResult();
        $countryCode = $organization->country_code;

        foreach ($lines as $index => $line) {
            $taxableAmount = $this->getLineSubtotal($line);
            $taxCategory = $this->getTaxCategory($line);
            $taxRate = $this->getVatRate($countryCode, $taxCategory);

            $taxAmount = '0';
            if ($taxRate > 0 && $taxCategory && $taxCategory->isTaxable()) {
                $taxAmount = bcmul((string) $taxableAmount, bcdiv((string) $taxRate, '100', 6), 4);
            }

            $result->lines[$index] = [
                'taxable_amount' => $taxableAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => (float) $taxAmount,
                'tax_code' => $taxCategory?->code ?? 'S',
            ];

            $result->totalTaxableAmount = bcadd((string) $result->totalTaxableAmount, (string) $taxableAmount, 4);
            $result->totalTaxAmount = bcadd((string) $result->totalTaxAmount, $taxAmount, 4);
        }

        // Group by tax rate for summary
        $result->taxSummary = $this->groupByTaxRate($result->lines);

        return $result;
    }

    /**
     * Calculate GST for India.
     */
    protected function calculateGst(
        Organization $organization,
        array $lines,
        ?string $placeOfSupply
    ): TaxResult {
        $result = new TaxResult();
        $result->isGst = true;

        // Determine if inter-state or intra-state
        $sellerState = $organization->state_code ?? substr($organization->tax_number ?? '', 0, 2);
        $buyerState = $placeOfSupply;
        $isInterState = $sellerState !== $buyerState && $buyerState !== null;

        $result->isInterState = $isInterState;
        $result->placeOfSupply = $placeOfSupply;

        foreach ($lines as $index => $line) {
            $taxableAmount = $this->getLineSubtotal($line);
            $gstRate = $this->getGstRate($line);

            if ($isInterState) {
                // IGST = full rate
                $igstAmount = bcmul((string) $taxableAmount, bcdiv((string) $gstRate, '100', 6), 4);

                $result->lines[$index] = [
                    'taxable_amount' => $taxableAmount,
                    'igst_rate' => $gstRate,
                    'igst_amount' => (float) $igstAmount,
                    'cgst_rate' => 0,
                    'cgst_amount' => 0,
                    'sgst_rate' => 0,
                    'sgst_amount' => 0,
                    'tax_amount' => (float) $igstAmount,
                    'hsn_code' => $line['hsn_code'] ?? null,
                ];

                $result->totalIgst = bcadd((string) $result->totalIgst, $igstAmount, 4);
            } else {
                // CGST + SGST = half each
                $halfRate = bcdiv((string) $gstRate, '2', 4);
                $cgstAmount = bcmul((string) $taxableAmount, bcdiv($halfRate, '100', 6), 4);
                $sgstAmount = $cgstAmount; // Same as CGST

                $result->lines[$index] = [
                    'taxable_amount' => $taxableAmount,
                    'igst_rate' => 0,
                    'igst_amount' => 0,
                    'cgst_rate' => (float) $halfRate,
                    'cgst_amount' => (float) $cgstAmount,
                    'sgst_rate' => (float) $halfRate,
                    'sgst_amount' => (float) $sgstAmount,
                    'tax_amount' => (float) bcadd($cgstAmount, $sgstAmount, 4),
                    'hsn_code' => $line['hsn_code'] ?? null,
                ];

                $result->totalCgst = bcadd((string) $result->totalCgst, $cgstAmount, 4);
                $result->totalSgst = bcadd((string) $result->totalSgst, $sgstAmount, 4);
            }

            $result->totalTaxableAmount = bcadd((string) $result->totalTaxableAmount, (string) $taxableAmount, 4);
            $result->totalTaxAmount = bcadd(
                (string) $result->totalTaxAmount,
                (string) $result->lines[$index]['tax_amount'],
                4
            );
        }

        // Group by rate for GST summary
        $result->taxSummary = $this->groupByGstRate($result->lines, $isInterState);

        return $result;
    }

    /**
     * No tax calculation.
     */
    protected function noTax(array $lines): TaxResult
    {
        $result = new TaxResult();

        foreach ($lines as $index => $line) {
            $taxableAmount = $this->getLineSubtotal($line);

            $result->lines[$index] = [
                'taxable_amount' => $taxableAmount,
                'tax_rate' => 0,
                'tax_amount' => 0,
            ];

            $result->totalTaxableAmount = bcadd((string) $result->totalTaxableAmount, (string) $taxableAmount, 4);
        }

        return $result;
    }

    /**
     * Get line subtotal (before tax).
     */
    protected function getLineSubtotal(array $line): float
    {
        $quantity = $line['quantity'] ?? 1;
        $unitPrice = $line['unit_price'] ?? 0;
        $discountAmount = $line['discount_amount'] ?? 0;

        $gross = bcmul((string) $quantity, (string) $unitPrice, 4);
        return (float) bcsub($gross, (string) $discountAmount, 4);
    }

    /**
     * Get tax category from line.
     */
    protected function getTaxCategory(array $line): ?TaxCategory
    {
        if (isset($line['tax_category_id'])) {
            return TaxCategory::find($line['tax_category_id']);
        }

        return TaxCategory::where('code', $line['tax_code'] ?? 'S')->first();
    }

    /**
     * Get VAT rate for a country.
     */
    protected function getVatRate(string $countryCode, ?TaxCategory $taxCategory): float
    {
        if (!$taxCategory || !$taxCategory->isTaxable()) {
            return 0;
        }

        $rate = TaxRate::where('tax_category_id', $taxCategory->id)
            ->forCountry($countryCode)
            ->effectiveOn(now())
            ->active()
            ->first();

        return (float) ($rate?->rate ?? 0);
    }

    /**
     * Get GST rate from line.
     */
    protected function getGstRate(array $line): float
    {
        // Explicit rate in line
        if (isset($line['gst_rate'])) {
            return (float) $line['gst_rate'];
        }

        // Get from HSN code
        if (isset($line['hsn_code'])) {
            $hsn = \App\Models\Tax\HsnSacCode::where('code', $line['hsn_code'])->first();
            if ($hsn) {
                return (float) $hsn->gst_rate;
            }
        }

        // Default 18%
        return 18.0;
    }

    /**
     * Group lines by tax rate for summary.
     */
    protected function groupByTaxRate(array $lines): array
    {
        $summary = [];

        foreach ($lines as $line) {
            $rate = $line['tax_rate'];
            $key = (string) $rate;

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'rate' => $rate,
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                ];
            }

            $summary[$key]['taxable_amount'] = bcadd(
                (string) $summary[$key]['taxable_amount'],
                (string) $line['taxable_amount'],
                4
            );
            $summary[$key]['tax_amount'] = bcadd(
                (string) $summary[$key]['tax_amount'],
                (string) $line['tax_amount'],
                4
            );
        }

        return array_values($summary);
    }

    /**
     * Group lines by GST rate for summary.
     */
    protected function groupByGstRate(array $lines, bool $isInterState): array
    {
        $summary = [];

        foreach ($lines as $line) {
            $rate = $isInterState ? $line['igst_rate'] : ($line['cgst_rate'] + $line['sgst_rate']);
            $key = (string) $rate;

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'rate' => $rate,
                    'taxable_amount' => 0,
                    'igst_amount' => 0,
                    'cgst_amount' => 0,
                    'sgst_amount' => 0,
                    'total_tax' => 0,
                ];
            }

            $summary[$key]['taxable_amount'] = bcadd(
                (string) $summary[$key]['taxable_amount'],
                (string) $line['taxable_amount'],
                4
            );

            if ($isInterState) {
                $summary[$key]['igst_amount'] = bcadd(
                    (string) $summary[$key]['igst_amount'],
                    (string) $line['igst_amount'],
                    4
                );
            } else {
                $summary[$key]['cgst_amount'] = bcadd(
                    (string) $summary[$key]['cgst_amount'],
                    (string) $line['cgst_amount'],
                    4
                );
                $summary[$key]['sgst_amount'] = bcadd(
                    (string) $summary[$key]['sgst_amount'],
                    (string) $line['sgst_amount'],
                    4
                );
            }

            $summary[$key]['total_tax'] = bcadd(
                (string) $summary[$key]['total_tax'],
                (string) $line['tax_amount'],
                4
            );
        }

        return array_values($summary);
    }

    /**
     * Calculate tax-inclusive to tax-exclusive conversion.
     */
    public function extractTaxFromInclusive(float $inclusiveAmount, float $taxRate): array
    {
        $divisor = bcadd('1', bcdiv((string) $taxRate, '100', 6), 6);
        $baseAmount = bcdiv((string) $inclusiveAmount, $divisor, 4);
        $taxAmount = bcsub((string) $inclusiveAmount, $baseAmount, 4);

        return [
            'base_amount' => (float) $baseAmount,
            'tax_amount' => (float) $taxAmount,
            'tax_rate' => $taxRate,
        ];
    }
}

/**
 * Tax calculation result.
 */
class TaxResult
{
    public array $lines = [];
    public array $taxSummary = [];
    public float $totalTaxableAmount = 0;
    public float $totalTaxAmount = 0;

    // GST specific
    public bool $isGst = false;
    public bool $isInterState = false;
    public ?string $placeOfSupply = null;
    public float $totalCgst = 0;
    public float $totalSgst = 0;
    public float $totalIgst = 0;

    public function toArray(): array
    {
        $data = [
            'lines' => $this->lines,
            'summary' => $this->taxSummary,
            'totals' => [
                'taxable_amount' => $this->totalTaxableAmount,
                'tax_amount' => $this->totalTaxAmount,
            ],
        ];

        if ($this->isGst) {
            $data['gst'] = [
                'is_inter_state' => $this->isInterState,
                'place_of_supply' => $this->placeOfSupply,
                'cgst' => $this->totalCgst,
                'sgst' => $this->totalSgst,
                'igst' => $this->totalIgst,
            ];
        }

        return $data;
    }
}
