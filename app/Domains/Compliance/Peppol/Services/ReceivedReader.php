<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\DTOs\ReceivedSummary;
use DOMDocument;
use DOMXPath;

/**
 * Reads the few fields a person looks for in a received invoice: its number,
 * dates, seller, currency and amounts.
 *
 * The XML came from another supplier, so it is read defensively. A document
 * with a DOCTYPE is not read at all - UBL has none, and a DOCTYPE is how
 * entities are declared - and LIBXML_NONET keeps the parser off the network.
 * Entities are never substituted (no LIBXML_NOENT). What comes back is text,
 * cut to a length a screen can show; the view escapes it like any other.
 */
final class ReceivedReader
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** Longest value shown; a hostile document can make a field any length. */
    private const MAX = 255;

    public function summarise(?string $xml): ReceivedSummary
    {
        $xpath = $this->open((string) $xml);

        if ($xpath === null) {
            return new ReceivedSummary;
        }

        $text = fn (string $path): string => mb_substr(trim((string) $xpath->evaluate('string(/*/'.$path.')')), 0, self::MAX);
        $supplier = 'cac:AccountingSupplierParty/cac:Party/';

        return new ReceivedSummary(
            kind: mb_substr((string) $xpath->document->documentElement?->localName, 0, 30),
            number: $text('cbc:ID'),
            issueDate: $text('cbc:IssueDate'),
            dueDate: $text('cbc:DueDate'),
            seller: $text($supplier.'cac:PartyLegalEntity/cbc:RegistrationName') ?: $text($supplier.'cac:PartyName/cbc:Name'),
            sellerTaxId: $text($supplier.'cac:PartyTaxScheme/cbc:CompanyID'),
            currency: $text('cbc:DocumentCurrencyCode'),
            total: $text('cac:LegalMonetaryTotal/cbc:PayableAmount'),
            tax: $text('cac:TaxTotal/cbc:TaxAmount'),
        );
    }

    private function open(string $xml): ?DOMXPath
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $dom->documentElement === null || $dom->doctype !== null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }
}
