<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use DOMDocument;
use DOMElement;

/**
 * The seller and the buyer of an invoice, with their addresses.
 */
class UblPartyBuilder
{
    /**
     * The supplier, then the customer.
     */
    public function addParties(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $this->addSupplierParty($dom, $data);
        $this->addCustomerParty($dom, $data);
    }

    /**
     * Add supplier (seller) party.
     *
     * Per ZATCA specifications, use CRN as primary PartyIdentification if available,
     * VAT number goes in PartyTaxScheme/CompanyID.
     */
    private function addSupplierParty(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $supplier = $dom->createElementNS(Ubl::CAC_NS, 'cac:AccountingSupplierParty');
        $party = $dom->createElementNS(Ubl::CAC_NS, 'cac:Party');

        // The seller identification (BT-29), once, as ZATCA's samples carry
        // it: the commercial registration number under scheme CRN. BR-KSA-08
        // allows CRN, MOM, MLS, SAG, OTH and 700 - never VAT, which this fell
        // back to without a CRN. With none there is none to write: issuing a
        // standard invoice and onboarding refuse that earlier
        // (InvoiceValidator, Organization::hasCompleteZatcaProfile()).
        if (filled($data->sellerCrNumber)) {
            $partyId = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyIdentification');
            $idElement = Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', $data->sellerCrNumber);
            $idElement->setAttribute('schemeID', 'CRN');
            $partyId->appendChild($idElement);
            $party->appendChild($partyId);
        }

        // Postal address
        $party->appendChild($this->buildAddress($dom, $data->sellerAddress));

        // Party tax scheme
        $taxScheme = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyTaxScheme');
        $taxScheme->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:CompanyID', $data->sellerVatNumber));
        $taxSchemeInner = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxScheme');
        $taxSchemeInner->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', 'VAT'));
        $taxScheme->appendChild($taxSchemeInner);
        $party->appendChild($taxScheme);

        // Party legal entity (name)
        $legalEntity = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyLegalEntity');
        $legalEntity->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:RegistrationName', $data->sellerName));
        $party->appendChild($legalEntity);

        $supplier->appendChild($party);
        $dom->documentElement->appendChild($supplier);
    }

    /**
     * Add customer (buyer) party.
     *
     * Per ZATCA specification:
     * - If buyer has VAT: No PartyIdentification, only PartyTaxScheme with CompanyID
     * - If buyer has NO VAT: PartyIdentification required with other schemeID (TIN, CRN, NAT, etc.)
     *
     * Valid schemeIDs for non-VAT buyers:
     * - TIN: Tax Identification Number
     * - CRN: Commercial Registration Number
     * - MOM: Momra License
     * - MLS: MLSD License
     * - SAG: Sagia License
     * - NAT: National ID (Saudis)
     * - GCC: GCC ID
     * - IQA: Iqama Number
     * - PAS: Passport Number
     * - OTH: Other ID
     */
    private function addCustomerParty(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $customer = $dom->createElementNS(Ubl::CAC_NS, 'cac:AccountingCustomerParty');
        $party = $dom->createElementNS(Ubl::CAC_NS, 'cac:Party');

        // Party identification - required when buyer is NOT VAT registered
        // Per ZATCA: buyers without VAT must have alternative ID
        if (! $data->buyerHasVat() && $data->buyerHasAlternativeId()) {
            $partyId = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyIdentification');
            $idElement = Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', $data->buyerId);
            $idElement->setAttribute('schemeID', $data->buyerIdScheme);
            $partyId->appendChild($idElement);
            $party->appendChild($partyId);
        }

        // Postal address (if provided)
        if ($data->buyerAddress !== null) {
            $party->appendChild($this->buildAddress($dom, $data->buyerAddress));
        }

        // Party tax scheme (for B2B with VAT)
        if ($data->buyerHasVat()) {
            $taxScheme = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyTaxScheme');
            $taxScheme->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:CompanyID', $data->buyerVatNumber));
            $taxSchemeInner = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxScheme');
            $taxSchemeInner->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', 'VAT'));
            $taxScheme->appendChild($taxSchemeInner);
            $party->appendChild($taxScheme);
        }

        // Party legal entity (name), only when there is one.
        //
        // This always wrote cbc:RegistrationName, and with no name to write it
        // threw a TypeError - so a walk-in sale could not be built at all, and
        // the form forced the cashier to invent a name to get past it.
        // BR-KSA-25 makes the name optional on a simplified invoice outside the
        // EDU and HEA exemptions.
        if ($data->buyerName !== null && trim($data->buyerName) !== '') {
            $legalEntity = $dom->createElementNS(Ubl::CAC_NS, 'cac:PartyLegalEntity');
            $legalEntity->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:RegistrationName', $data->buyerName));
            $party->appendChild($legalEntity);
        }

        // A buyer with nothing to say about them is an empty customer party,
        // which is what ZATCA's own sample simplified invoice carries. An
        // empty cac:Party inside it is not the same thing and is not what the
        // sample does.
        if ($party->hasChildNodes()) {
            $customer->appendChild($party);
        }

        $dom->documentElement->appendChild($customer);
    }

    /**
     * Build address element.
     *
     * Element order per UBL 2.1 and ZATCA specifications:
     * 1. StreetName
     * 2. AdditionalStreetName (optional)
     * 3. BuildingNumber
     * 4. PlotIdentification (optional)
     * 5. CitySubdivisionName (district)
     * 6. CityName
     * 7. PostalZone
     * 8. CountrySubentity (optional, region/state)
     * 9. Country/IdentificationCode
     */
    private function buildAddress(DOMDocument $dom, AddressData $address): DOMElement
    {
        $postalAddress = $dom->createElementNS(Ubl::CAC_NS, 'cac:PostalAddress');

        // 1. StreetName (mandatory)
        $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:StreetName', $address->street));

        // 2. AdditionalStreetName (optional)
        if ($address->additionalStreet !== null) {
            $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:AdditionalStreetName', $address->additionalStreet));
        }

        // 3. BuildingNumber (mandatory per ZATCA)
        if ($address->buildingNumber !== null) {
            $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:BuildingNumber', $address->buildingNumber));
        }

        // 4. PlotIdentification (optional - for plot/land identification)
        if ($address->plotIdentification !== null) {
            $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:PlotIdentification', $address->plotIdentification));
        }

        // 5. CitySubdivisionName (district - mandatory per ZATCA)
        if ($address->district !== null) {
            $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:CitySubdivisionName', $address->district));
        }

        // 6. CityName (mandatory)
        $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:CityName', $address->city));

        // 7. PostalZone (mandatory)
        $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:PostalZone', $address->postalCode));

        // 8. CountrySubentity (optional - region/state)
        if ($address->countrySubentity !== null) {
            $postalAddress->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:CountrySubentity', $address->countrySubentity));
        }

        // 9. Country (mandatory)
        $country = $dom->createElementNS(Ubl::CAC_NS, 'cac:Country');
        $country->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:IdentificationCode', $address->countryCode));
        $postalAddress->appendChild($country);

        return $postalAddress;
    }
}
