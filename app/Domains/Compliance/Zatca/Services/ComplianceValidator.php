<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use DOMDocument;
use DOMXPath;

/**
 * ZATCA Compliance Validator
 *
 * Validates invoice XML against ZATCA Phase 2 requirements.
 * This provides local validation before submitting to ZATCA SDK or API.
 *
 * Validation layers:
 * 1. XML Well-formedness
 * 2. UBL 2.1 Schema validation
 * 3. ZATCA Business Rules (BR)
 * 4. KSA-specific requirements
 */
class ComplianceValidator
{
    private const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private array $errors = [];
    private array $warnings = [];
    private DOMDocument $dom;
    private DOMXPath $xpath;

    /**
     * Validate invoice XML.
     *
     * @param string $xml The invoice XML content
     * @return array Validation result with 'valid', 'errors', 'warnings' keys
     */
    public function validate(string $xml): array
    {
        $this->errors = [];
        $this->warnings = [];

        // 1. XML Well-formedness
        if (!$this->validateWellFormedness($xml)) {
            return $this->result();
        }

        // 2. Required elements
        $this->validateRequiredElements();

        // 3. Element values and formats
        $this->validateElementFormats();

        // 4. Business rules
        $this->validateBusinessRules();

        // 5. KSA-specific requirements
        $this->validateKsaRequirements();

        return $this->result();
    }

    /**
     * Validate XML well-formedness.
     */
    private function validateWellFormedness(string $xml): bool
    {
        $this->dom = new DOMDocument();

        // Suppress warnings during load
        $previousErrors = libxml_use_internal_errors(true);

        $loaded = $this->dom->loadXML($xml);

        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded) {
            $this->errors[] = [
                'code' => 'XML_MALFORMED',
                'message' => 'XML is not well-formed',
                'details' => array_map(fn($e) => $e->message, $xmlErrors),
            ];
            return false;
        }

        // Setup XPath
        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('inv', self::UBL_NS);
        $this->xpath->registerNamespace('cac', self::CAC_NS);
        $this->xpath->registerNamespace('cbc', self::CBC_NS);

        return true;
    }

    /**
     * Validate required elements exist.
     */
    private function validateRequiredElements(): void
    {
        $required = [
            // Root level
            ['xpath' => '//cbc:UBLVersionID', 'name' => 'UBLVersionID', 'rule' => 'BR-01'],
            ['xpath' => '//cbc:ID', 'name' => 'Invoice ID', 'rule' => 'BR-02'],
            ['xpath' => '//cbc:UUID', 'name' => 'UUID', 'rule' => 'KSA-01'],
            ['xpath' => '//cbc:IssueDate', 'name' => 'Issue Date', 'rule' => 'BR-03'],
            ['xpath' => '//cbc:IssueTime', 'name' => 'Issue Time', 'rule' => 'KSA-25'],
            ['xpath' => '//cbc:InvoiceTypeCode', 'name' => 'Invoice Type Code', 'rule' => 'BR-04'],
            ['xpath' => '//cbc:DocumentCurrencyCode', 'name' => 'Currency Code', 'rule' => 'BR-05'],

            // ICV and PIH
            ['xpath' => "//cac:AdditionalDocumentReference[cbc:ID='ICV']", 'name' => 'ICV Reference', 'rule' => 'KSA-16'],
            ['xpath' => "//cac:AdditionalDocumentReference[cbc:ID='PIH']", 'name' => 'PIH Reference', 'rule' => 'KSA-13'],

            // Supplier
            ['xpath' => '//cac:AccountingSupplierParty', 'name' => 'Supplier Party', 'rule' => 'BR-06'],
            ['xpath' => '//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:RegistrationName', 'name' => 'Supplier Name', 'rule' => 'BR-07'],
            ['xpath' => '//cac:AccountingSupplierParty//cac:PostalAddress', 'name' => 'Supplier Address', 'rule' => 'BR-08'],
            ['xpath' => '//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID', 'name' => 'Supplier VAT', 'rule' => 'BR-CO-26'],

            // Customer
            ['xpath' => '//cac:AccountingCustomerParty', 'name' => 'Customer Party', 'rule' => 'BR-10'],
            ['xpath' => '//cac:AccountingCustomerParty//cac:PartyLegalEntity/cbc:RegistrationName', 'name' => 'Customer Name', 'rule' => 'BR-11'],

            // Totals
            ['xpath' => '//cac:TaxTotal/cbc:TaxAmount', 'name' => 'Tax Total', 'rule' => 'BR-CO-14'],
            ['xpath' => '//cac:LegalMonetaryTotal', 'name' => 'Legal Monetary Total', 'rule' => 'BR-12'],
            ['xpath' => '//cac:LegalMonetaryTotal/cbc:PayableAmount', 'name' => 'Payable Amount', 'rule' => 'BR-CO-25'],

            // Lines
            ['xpath' => '//cac:InvoiceLine', 'name' => 'Invoice Line', 'rule' => 'BR-16'],
        ];

        foreach ($required as $element) {
            $nodes = $this->xpath->query($element['xpath']);
            if ($nodes->length === 0) {
                $this->errors[] = [
                    'code' => $element['rule'],
                    'message' => "Missing required element: {$element['name']}",
                    'xpath' => $element['xpath'],
                ];
            }
        }
    }

    /**
     * Validate element formats and values.
     */
    private function validateElementFormats(): void
    {
        // UBLVersionID must be 2.1
        $version = $this->getNodeValue('//cbc:UBLVersionID');
        if ($version !== null && $version !== '2.1') {
            $this->errors[] = [
                'code' => 'BR-01',
                'message' => "UBLVersionID must be '2.1', got '{$version}'",
            ];
        }

        // UUID format (UUID v4)
        $uuid = $this->getNodeValue('//cbc:UUID');
        if ($uuid !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            $this->warnings[] = [
                'code' => 'KSA-01',
                'message' => 'UUID should be in UUID v4 format',
            ];
        }

        // Issue Date format (YYYY-MM-DD)
        $issueDate = $this->getNodeValue('//cbc:IssueDate');
        if ($issueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            $this->errors[] = [
                'code' => 'BR-03',
                'message' => 'Issue Date must be in YYYY-MM-DD format',
            ];
        }

        // Issue Time format (HH:MM:SS)
        $issueTime = $this->getNodeValue('//cbc:IssueTime');
        if ($issueTime !== null && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $issueTime)) {
            $this->errors[] = [
                'code' => 'KSA-25',
                'message' => 'Issue Time must be in HH:MM:SS format',
            ];
        }

        // Currency must be SAR or valid ISO 4217
        $currency = $this->getNodeValue('//cbc:DocumentCurrencyCode');
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->errors[] = [
                'code' => 'BR-05',
                'message' => 'Currency code must be a valid ISO 4217 code',
            ];
        }

        // VAT number format (15 digits starting AND ending with 3)
        $sellerVat = $this->getNodeValue('//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID');
        if ($sellerVat !== null && !preg_match('/^3\d{13}3$/', $sellerVat)) {
            $this->warnings[] = [
                'code' => 'KSA-02',
                'message' => 'Saudi VAT number should be 15 digits starting and ending with 3',
            ];
        }

        // Postal code format (5 digits)
        $postalCode = $this->getNodeValue('//cac:AccountingSupplierParty//cac:PostalAddress/cbc:PostalZone');
        if ($postalCode !== null && !preg_match('/^\d{5}$/', $postalCode)) {
            $this->warnings[] = [
                'code' => 'KSA-17',
                'message' => 'Postal code should be 5 digits',
            ];
        }
    }

    /**
     * Validate ZATCA business rules.
     */
    private function validateBusinessRules(): void
    {
        // BR-CO-15: Tax amount = Sum of tax subtotals
        $taxTotal = (float) $this->getNodeValue('//cac:TaxTotal/cbc:TaxAmount');
        $taxSubtotals = $this->xpath->query('//cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount');
        $sumSubtotals = 0.0;
        foreach ($taxSubtotals as $node) {
            $sumSubtotals += (float) $node->nodeValue;
        }

        if (abs($taxTotal - $sumSubtotals) > 0.01) {
            $this->errors[] = [
                'code' => 'BR-CO-15',
                'message' => "Tax total ({$taxTotal}) must equal sum of tax subtotals ({$sumSubtotals})",
            ];
        }

        // BR-CO-25: Payable amount calculation
        $taxExclusive = (float) $this->getNodeValue('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount') ?: 0;
        $taxInclusive = (float) $this->getNodeValue('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount') ?: 0;
        $payable = (float) $this->getNodeValue('//cac:LegalMonetaryTotal/cbc:PayableAmount') ?: 0;
        $prepaid = (float) $this->getNodeValue('//cac:LegalMonetaryTotal/cbc:PrepaidAmount') ?: 0;

        $expectedPayable = $taxInclusive - $prepaid;
        if (abs($payable - $expectedPayable) > 0.01) {
            $this->warnings[] = [
                'code' => 'BR-CO-25',
                'message' => "Payable amount ({$payable}) should equal tax inclusive ({$taxInclusive}) minus prepaid ({$prepaid})",
            ];
        }

        // BR-S-08: Standard rate must be > 0
        $standardRateNodes = $this->xpath->query("//cac:TaxCategory[cbc:ID='S']/cbc:Percent");
        foreach ($standardRateNodes as $node) {
            $rate = (float) $node->nodeValue;
            if ($rate <= 0) {
                $this->errors[] = [
                    'code' => 'BR-S-08',
                    'message' => 'Standard rate VAT (category S) must have a rate > 0',
                ];
            }
        }

        // Check for duplicate line IDs
        $lineIds = [];
        $lineIdNodes = $this->xpath->query('//cac:InvoiceLine/cbc:ID');
        foreach ($lineIdNodes as $node) {
            $id = $node->nodeValue;
            if (in_array($id, $lineIds, true)) {
                $this->errors[] = [
                    'code' => 'BR-21',
                    'message' => "Duplicate invoice line ID: {$id}",
                ];
            }
            $lineIds[] = $id;
        }
    }

    /**
     * Validate KSA-specific requirements.
     */
    private function validateKsaRequirements(): void
    {
        // KSA-2: Seller VAT is mandatory
        $sellerVat = $this->getNodeValue('//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID');
        if (empty($sellerVat)) {
            $this->errors[] = [
                'code' => 'KSA-02',
                'message' => 'Seller VAT registration number is mandatory',
            ];
        }

        // KSA-5: Seller address fields
        $requiredAddressFields = [
            'StreetName' => 'KSA-05',
            'BuildingNumber' => 'KSA-18',
            'CitySubdivisionName' => 'KSA-19',
            'CityName' => 'KSA-20',
            'PostalZone' => 'KSA-21',
        ];

        foreach ($requiredAddressFields as $field => $code) {
            $value = $this->getNodeValue("//cac:AccountingSupplierParty//cac:PostalAddress/cbc:{$field}");
            if (empty($value)) {
                $this->errors[] = [
                    'code' => $code,
                    'message' => "Seller address {$field} is mandatory",
                ];
            }
        }

        // KSA-13: PIH must be base64 encoded
        $pih = $this->getNodeValue("//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject");
        if ($pih !== null && base64_decode($pih, true) === false) {
            $this->errors[] = [
                'code' => 'KSA-13',
                'message' => 'PIH (Previous Invoice Hash) must be base64 encoded',
            ];
        }

        // Check invoice type code
        $typeCode = $this->getNodeValue('//cbc:InvoiceTypeCode');
        $validTypes = ['388', '381', '383']; // Tax Invoice, Credit Note, Debit Note
        if ($typeCode !== null && !in_array($typeCode, $validTypes, true)) {
            $this->warnings[] = [
                'code' => 'KSA-06',
                'message' => "Invoice type code '{$typeCode}' - expected one of: " . implode(', ', $validTypes),
            ];
        }

        // Check for QR code placeholder
        $qr = $this->getNodeValue("//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject");
        if (empty($qr)) {
            $this->warnings[] = [
                'code' => 'KSA-14',
                'message' => 'QR code is empty (will be populated after signing)',
            ];
        }
    }

    /**
     * Get node value by XPath.
     */
    private function getNodeValue(string $xpath): ?string
    {
        $nodes = $this->xpath->query($xpath);
        if ($nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }
        return null;
    }

    /**
     * Build result array.
     */
    private function result(): array
    {
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error_count' => count($this->errors),
            'warning_count' => count($this->warnings),
        ];
    }

    /**
     * Get human-readable validation report.
     */
    public function getReport(array $result): string
    {
        $lines = [];
        $lines[] = '=== ZATCA Compliance Validation Report ===';
        $lines[] = '';
        $lines[] = $result['valid'] ? '✓ VALID - No errors found' : '✗ INVALID - Errors found';
        $lines[] = "Errors: {$result['error_count']}, Warnings: {$result['warning_count']}";
        $lines[] = '';

        if (!empty($result['errors'])) {
            $lines[] = 'ERRORS:';
            foreach ($result['errors'] as $error) {
                $lines[] = "  [{$error['code']}] {$error['message']}";
            }
            $lines[] = '';
        }

        if (!empty($result['warnings'])) {
            $lines[] = 'WARNINGS:';
            foreach ($result['warnings'] as $warning) {
                $lines[] = "  [{$warning['code']}] {$warning['message']}";
            }
        }

        return implode("\n", $lines);
    }
}
