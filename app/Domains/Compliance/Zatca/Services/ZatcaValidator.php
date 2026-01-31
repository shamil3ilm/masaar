<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\AddressData;
use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * ZATCA validation service.
 *
 * Validates invoices against ZATCA business rules before submission.
 */
class ZatcaValidator
{
    /**
     * Validate invoice for ZATCA compliance.
     *
     * @param Invoice $invoice
     * @param Organization $organization
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validate(Invoice $invoice, Organization $organization): array
    {
        $errors = [];
        $warnings = [];

        // Validate organization profile
        $errors = array_merge($errors, $this->validateOrganization($organization));

        // Validate invoice fields
        $errors = array_merge($errors, $this->validateInvoice($invoice));

        // Validate buyer information
        $errors = array_merge($errors, $this->validateBuyer($invoice));

        // Validate line items
        $errors = array_merge($errors, $this->validateLines($invoice));

        // Validate amounts
        $errors = array_merge($errors, $this->validateAmounts($invoice));

        // Check for warnings
        $warnings = array_merge($warnings, $this->checkWarnings($invoice, $organization));

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate organization (seller) information.
     */
    private function validateOrganization(Organization $organization): array
    {
        $errors = [];

        // VAT number (BR-KSA-14)
        if (! $organization->vat_number) {
            $errors[] = 'BR-KSA-14: Seller VAT number is required';
        } elseif (! $this->isValidVatNumber($organization->vat_number)) {
            $errors[] = 'BR-KSA-14: Seller VAT number must be 15 digits starting with 3';
        }

        // Seller name
        if (! $organization->name) {
            $errors[] = 'BR-S-01: Seller name is required';
        }

        // Address (BR-KSA-09)
        if (! $organization->street) {
            $errors[] = 'BR-KSA-09: Seller street is required';
        }
        if (! $organization->city) {
            $errors[] = 'BR-KSA-09: Seller city is required';
        }
        if (! $organization->postal_code) {
            $errors[] = 'BR-KSA-09: Seller postal code is required';
        } elseif (! $this->isValidPostalCode($organization->postal_code)) {
            $errors[] = 'BR-KSA-09: Postal code must be 5 digits';
        }

        return $errors;
    }

    /**
     * Validate invoice fields.
     */
    private function validateInvoice(Invoice $invoice): array
    {
        $errors = [];

        // Invoice number (BT-1)
        if (! $invoice->invoice_number) {
            $errors[] = 'BT-1: Invoice number is required';
        }

        // Issue date (BT-2)
        if (! $invoice->issue_date) {
            $errors[] = 'BT-2: Issue date is required';
        }

        // Invoice type
        if (! $invoice->type) {
            $errors[] = 'BT-3: Invoice type is required';
        }

        // Currency (BT-5)
        if (! $invoice->currency) {
            $errors[] = 'BT-5: Currency code is required';
        }

        // Billing reference for credit/debit notes
        $docType = $invoice->document_type ?? DocumentType::Invoice;
        if ($docType->requiresBillingReference() && ! $invoice->billing_reference_id) {
            $errors[] = 'BT-25: Billing reference is required for credit/debit notes';
        }

        return $errors;
    }

    /**
     * Validate buyer information.
     */
    private function validateBuyer(Invoice $invoice): array
    {
        $errors = [];

        // Buyer name (BT-44)
        if (! $invoice->buyer_name) {
            $errors[] = 'BT-44: Buyer name is required';
        }

        // For standard (B2B) invoices, buyer VAT is required
        if ($invoice->type->value === 'standard') {
            if (! $invoice->buyer_vat_number) {
                $errors[] = 'BR-KSA-15: Buyer VAT number is required for standard invoices';
            } elseif (! $this->isValidVatNumber($invoice->buyer_vat_number)) {
                $errors[] = 'BR-KSA-15: Buyer VAT number must be 15 digits starting with 3';
            }
        }

        return $errors;
    }

    /**
     * Validate invoice lines.
     */
    private function validateLines(Invoice $invoice): array
    {
        $errors = [];

        if ($invoice->lines->isEmpty()) {
            $errors[] = 'BR-16: Invoice must have at least one line item';
            return $errors;
        }

        foreach ($invoice->lines as $index => $line) {
            $lineNum = $index + 1;

            // Description (BT-153)
            if (! $line->description) {
                $errors[] = "BT-153: Line {$lineNum} description is required";
            }

            // Quantity (BT-129)
            if ($line->quantity <= 0) {
                $errors[] = "BT-129: Line {$lineNum} quantity must be positive";
            }

            // Unit price (BT-146)
            if ($line->unit_price < 0) {
                $errors[] = "BT-146: Line {$lineNum} unit price cannot be negative";
            }

            // Tax rate
            if ($line->tax_rate < 0 || $line->tax_rate > 100) {
                $errors[] = "BR-KSA-DEC-02: Line {$lineNum} tax rate must be between 0 and 100";
            }

            // Tax category validation (BR-KSA-33, BR-KSA-34, BR-KSA-35)
            $taxCategory = $line->tax_category ?? 'S';
            if (in_array($taxCategory, ['Z', 'E', 'O'])) {
                // Zero-rated, Exempt, and Out-of-scope require exemption reason
                if (empty($line->tax_exemption_code)) {
                    $errors[] = "BR-KSA-33: Line {$lineNum} requires exemption reason code for tax category {$taxCategory}";
                }
                if (empty($line->tax_exemption_reason)) {
                    $errors[] = "BR-KSA-34: Line {$lineNum} requires exemption reason text for tax category {$taxCategory}";
                }
            }

            // Validate tax category matches rate (BR-KSA-35)
            if ($taxCategory === 'S' && $line->tax_rate <= 0) {
                $errors[] = "BR-KSA-35: Line {$lineNum} standard rated (S) must have positive tax rate";
            }
            if (in_array($taxCategory, ['Z', 'E', 'O']) && $line->tax_rate > 0) {
                $errors[] = "BR-KSA-35: Line {$lineNum} tax category {$taxCategory} should have 0% tax rate";
            }
        }

        return $errors;
    }

    /**
     * Validate invoice amounts.
     */
    private function validateAmounts(Invoice $invoice): array
    {
        $errors = [];

        // Calculate expected totals
        $expectedSubtotal = $invoice->lines->sum(fn ($line) => $line->quantity * $line->unit_price);
        $expectedTax = $invoice->lines->sum('tax_amount');
        $discountAmount = (float) ($invoice->discount_amount ?? 0);
        $expectedTotal = $expectedSubtotal - $discountAmount + $expectedTax;

        // Subtotal validation (BR-CO-10)
        if (abs((float) $invoice->subtotal - $expectedSubtotal) > 0.01) {
            $errors[] = 'BR-CO-10: Subtotal does not match sum of line amounts';
        }

        // Tax amount validation (BR-CO-14)
        if (abs((float) $invoice->tax_amount - $expectedTax) > 0.01) {
            $errors[] = 'BR-CO-14: Tax total does not match sum of line taxes';
        }

        // Total validation (BR-CO-15) - accounting for discount
        if (abs((float) $invoice->total - $expectedTotal) > 0.01) {
            $errors[] = 'BR-CO-15: Invoice total does not match (subtotal - discount + tax)';
        }

        // Discount validation (BR-KSA-DEC-01)
        if ($discountAmount < 0) {
            $errors[] = 'BR-KSA-DEC-01: Discount amount cannot be negative';
        }
        if ($discountAmount > $expectedSubtotal) {
            $errors[] = 'BR-KSA-DEC-01: Discount cannot exceed invoice subtotal';
        }

        // Amounts must be positive
        if ((float) $invoice->total < 0) {
            $errors[] = 'BR-04: Invoice total must not be negative';
        }

        return $errors;
    }

    /**
     * Check for warnings (non-blocking issues).
     */
    private function checkWarnings(Invoice $invoice, Organization $organization): array
    {
        $warnings = [];

        // Large invoice warning
        if ((float) $invoice->total > 1000000) {
            $warnings[] = 'Large invoice amount - ensure accuracy';
        }

        // Zero-rated items warning
        foreach ($invoice->lines as $line) {
            if ($line->tax_rate == 0) {
                $warnings[] = "Line {$line->description} has 0% VAT - ensure exemption reason is provided";
                break;
            }
        }

        // Future date warning
        if ($invoice->issue_date->isFuture()) {
            $warnings[] = 'Invoice date is in the future';
        }

        return $warnings;
    }

    /**
     * Validate VAT number format.
     * Must be 15 digits starting with 3.
     */
    public function isValidVatNumber(?string $vatNumber): bool
    {
        if ($vatNumber === null) {
            return false;
        }

        return strlen($vatNumber) === 15
            && ctype_digit($vatNumber)
            && str_starts_with($vatNumber, '3');
    }

    /**
     * Validate postal code format.
     * Must be 5 digits.
     */
    public function isValidPostalCode(?string $postalCode): bool
    {
        if ($postalCode === null) {
            return false;
        }

        return preg_match('/^\d{5}$/', $postalCode) === 1;
    }

    /**
     * Validate invoice XML against ZATCA XSD schema.
     * Returns array of validation errors.
     */
    public function validateXml(string $xml): array
    {
        $errors = [];

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        // For full validation, would load ZATCA XSD schema
        // $schemaPath = base_path('resources/zatca/Invoice.xsd');
        // $dom->schemaValidate($schemaPath);

        $xmlErrors = libxml_get_errors();
        foreach ($xmlErrors as $error) {
            $errors[] = "XML Error line {$error->line}: {$error->message}";
        }

        libxml_clear_errors();

        return $errors;
    }
}
