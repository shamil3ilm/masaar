<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\Exceptions\SigningException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * XAdES-BES digital signature service.
 *
 * Implements XML Advanced Electronic Signatures (XAdES) for ZATCA compliance.
 * Creates enveloped signatures embedded within the invoice XML.
 *
 * @see ETSI EN 319 132-1 (XAdES specification)
 */
class XadesSigner
{
    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';
    private const C14N_NS = 'http://www.w3.org/2006/12/xml-c14n11';

    public function __construct(
        private readonly EcdsaSigner $ecdsaSigner,
        private readonly CertificateService $certificateService,
    ) {}

    /**
     * Sign invoice XML with XAdES-BES signature.
     *
     * @param string $xml Invoice XML document
     * @param string $privateKeyPem Private key for signing
     * @param string $certificatePem X.509 certificate
     * @return string Signed XML document
     * @throws SigningException
     */
    public function sign(string $xml, string $privateKeyPem, string $certificatePem): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        // Generate signature ID
        $signatureId = 'signature-' . bin2hex(random_bytes(8));
        $signedPropertiesId = 'signedprops-' . bin2hex(random_bytes(8));

        // Create Signature element
        $signature = $this->createSignatureElement($dom, $signatureId);

        // Create XAdES Object first (need SignedProperties for digest calculation)
        $xadesResult = $this->createXadesObject($dom, $signedPropertiesId, $certificatePem);
        $object = $xadesResult['object'];
        $signedProperties = $xadesResult['signedProperties'];

        // Create SignedInfo (with SignedProperties digest)
        $signedInfo = $this->createSignedInfo($dom, $xml, $signedPropertiesId, $signedProperties);
        $signature->appendChild($signedInfo);

        // Calculate signature value
        $signedInfoC14n = $this->canonicalize($signedInfo);
        $signatureValue = $this->ecdsaSigner->sign($signedInfoC14n, $privateKeyPem);

        // Add SignatureValue
        $signatureValueElement = $dom->createElementNS(self::DS_NS, 'ds:SignatureValue', $signatureValue);
        $signature->appendChild($signatureValueElement);

        // Add KeyInfo with certificate
        $keyInfo = $this->createKeyInfo($dom, $certificatePem);
        $signature->appendChild($keyInfo);

        // Add XAdES Object (signed properties)
        $signature->appendChild($object);

        // Insert signature into document (after UBLExtensions)
        $this->insertSignature($dom, $signature);

        $dom->formatOutput = true;

        return $dom->saveXML();
    }

    /**
     * Create the main Signature element.
     */
    private function createSignatureElement(DOMDocument $dom, string $signatureId): DOMElement
    {
        $signature = $dom->createElementNS(self::DS_NS, 'ds:Signature');
        $signature->setAttribute('Id', $signatureId);

        return $signature;
    }

    /**
     * Create SignedInfo element with references.
     */
    private function createSignedInfo(DOMDocument $dom, string $xml, string $signedPropertiesId, DOMElement $signedProperties): DOMElement
    {
        $signedInfo = $dom->createElementNS(self::DS_NS, 'ds:SignedInfo');

        // CanonicalizationMethod
        $c14nMethod = $dom->createElementNS(self::DS_NS, 'ds:CanonicalizationMethod');
        $c14nMethod->setAttribute('Algorithm', self::C14N_NS);
        $signedInfo->appendChild($c14nMethod);

        // SignatureMethod (ECDSA with SHA-256)
        $sigMethod = $dom->createElementNS(self::DS_NS, 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256');
        $signedInfo->appendChild($sigMethod);

        // Reference to the document (invoice)
        $signedInfo->appendChild($this->createDocumentReference($dom, $xml));

        // Reference to SignedProperties (with calculated digest)
        $signedInfo->appendChild($this->createSignedPropertiesReference($dom, $signedPropertiesId, $signedProperties));

        return $signedInfo;
    }

    /**
     * Create reference to the main document.
     */
    private function createDocumentReference(DOMDocument $dom, string $xml): DOMElement
    {
        $reference = $dom->createElementNS(self::DS_NS, 'ds:Reference');
        $reference->setAttribute('Id', 'invoiceSignedData');
        $reference->setAttribute('URI', '');

        // Transforms
        $transforms = $dom->createElementNS(self::DS_NS, 'ds:Transforms');

        // Enveloped signature transform
        $transform1 = $dom->createElementNS(self::DS_NS, 'ds:Transform');
        $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($transform1);

        // XPath transform to exclude signature
        $transform2 = $dom->createElementNS(self::DS_NS, 'ds:Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/TR/1999/REC-xpath-19991116');
        $xpath = $dom->createElementNS(self::DS_NS, 'ds:XPath', 'not(//ancestor-or-self::ext:UBLExtensions)');
        $xpath->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $transform2->appendChild($xpath);
        $transforms->appendChild($transform2);

        // Canonicalization transform
        $transform3 = $dom->createElementNS(self::DS_NS, 'ds:Transform');
        $transform3->setAttribute('Algorithm', self::C14N_NS);
        $transforms->appendChild($transform3);

        $reference->appendChild($transforms);

        // DigestMethod
        $digestMethod = $dom->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        // DigestValue
        $digest = base64_encode(hash('sha256', $xml, true));
        $digestValue = $dom->createElementNS(self::DS_NS, 'ds:DigestValue', $digest);
        $reference->appendChild($digestValue);

        return $reference;
    }

    /**
     * Create reference to SignedProperties.
     */
    private function createSignedPropertiesReference(DOMDocument $dom, string $signedPropertiesId, DOMElement $signedProperties): DOMElement
    {
        $reference = $dom->createElementNS(self::DS_NS, 'ds:Reference');
        $reference->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $reference->setAttribute('URI', '#' . $signedPropertiesId);

        // DigestMethod
        $digestMethod = $dom->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        // Calculate digest of SignedProperties element (canonicalized)
        $signedPropsC14n = $signedProperties->C14N(true, false);
        $digest = base64_encode(hash('sha256', $signedPropsC14n, true));
        $digestValue = $dom->createElementNS(self::DS_NS, 'ds:DigestValue', $digest);
        $reference->appendChild($digestValue);

        return $reference;
    }

    /**
     * Create KeyInfo element with certificate.
     */
    private function createKeyInfo(DOMDocument $dom, string $certificatePem): DOMElement
    {
        $keyInfo = $dom->createElementNS(self::DS_NS, 'ds:KeyInfo');

        // X509Data
        $x509Data = $dom->createElementNS(self::DS_NS, 'ds:X509Data');

        // Extract certificate value (without headers)
        $certValue = $this->extractCertificateValue($certificatePem);
        $x509Cert = $dom->createElementNS(self::DS_NS, 'ds:X509Certificate', $certValue);
        $x509Data->appendChild($x509Cert);

        $keyInfo->appendChild($x509Data);

        return $keyInfo;
    }

    /**
     * Create XAdES Object with SignedProperties.
     *
     * @return array{object: DOMElement, signedProperties: DOMElement}
     */
    private function createXadesObject(DOMDocument $dom, string $signedPropertiesId, string $certificatePem): array
    {
        $object = $dom->createElementNS(self::DS_NS, 'ds:Object');

        // QualifyingProperties
        $qualifyingProps = $dom->createElementNS(self::XADES_NS, 'xades:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', '#signature');

        // SignedProperties
        $signedProps = $dom->createElementNS(self::XADES_NS, 'xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropertiesId);

        // SignedSignatureProperties
        $signedSigProps = $dom->createElementNS(self::XADES_NS, 'xades:SignedSignatureProperties');

        // SigningTime
        $signingTime = $dom->createElementNS(self::XADES_NS, 'xades:SigningTime', date('c'));
        $signedSigProps->appendChild($signingTime);

        // SigningCertificate
        $signingCert = $this->createSigningCertificate($dom, $certificatePem);
        $signedSigProps->appendChild($signingCert);

        $signedProps->appendChild($signedSigProps);
        $qualifyingProps->appendChild($signedProps);
        $object->appendChild($qualifyingProps);

        return [
            'object' => $object,
            'signedProperties' => $signedProps,
        ];
    }

    /**
     * Create SigningCertificate element.
     */
    private function createSigningCertificate(DOMDocument $dom, string $certificatePem): DOMElement
    {
        $signingCert = $dom->createElementNS(self::XADES_NS, 'xades:SigningCertificate');
        $cert = $dom->createElementNS(self::XADES_NS, 'xades:Cert');

        // CertDigest
        $certDigest = $dom->createElementNS(self::XADES_NS, 'xades:CertDigest');

        $digestMethod = $dom->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigest->appendChild($digestMethod);

        $certDer = $this->pemToDer($certificatePem);
        $digestValue = $dom->createElementNS(self::DS_NS, 'ds:DigestValue', base64_encode(hash('sha256', $certDer, true)));
        $certDigest->appendChild($digestValue);

        $cert->appendChild($certDigest);

        // IssuerSerial
        $issuerSerial = $dom->createElementNS(self::XADES_NS, 'xades:IssuerSerial');

        $certInfo = openssl_x509_parse($certificatePem);
        $issuerName = $dom->createElementNS(self::DS_NS, 'ds:X509IssuerName', $this->formatIssuerName($certInfo['issuer'] ?? []));
        $issuerSerial->appendChild($issuerName);

        $serialNumber = $dom->createElementNS(self::DS_NS, 'ds:X509SerialNumber', $certInfo['serialNumber'] ?? '');
        $issuerSerial->appendChild($serialNumber);

        $cert->appendChild($issuerSerial);
        $signingCert->appendChild($cert);

        return $signingCert;
    }

    /**
     * Insert signature into document.
     */
    private function insertSignature(DOMDocument $dom, DOMElement $signature): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        // Find UBLExtensions/UBLExtension/ExtensionContent
        $nodes = $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent');

        if ($nodes->length > 0) {
            // Replace comment placeholder with signature
            $extensionContent = $nodes->item(0);

            foreach ($extensionContent->childNodes as $child) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $extensionContent->removeChild($child);
                    break;
                }
            }

            $extensionContent->appendChild($dom->importNode($signature, true));
        } else {
            // Append to root if UBLExtensions not found
            $dom->documentElement->appendChild($signature);
        }
    }

    /**
     * Canonicalize XML element.
     */
    private function canonicalize(DOMElement $element): string
    {
        return $element->C14N(true, false);
    }

    /**
     * Extract certificate value from PEM.
     */
    private function extractCertificateValue(string $pem): string
    {
        $lines = explode("\n", $pem);
        $value = '';

        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $value .= trim($line);
            }
        }

        return $value;
    }

    /**
     * Convert PEM to DER format.
     */
    private function pemToDer(string $pem): string
    {
        return base64_decode($this->extractCertificateValue($pem));
    }

    /**
     * Format issuer name for X509IssuerName.
     */
    private function formatIssuerName(array $issuer): string
    {
        $parts = [];

        if (isset($issuer['CN'])) {
            $parts[] = 'CN=' . $issuer['CN'];
        }
        if (isset($issuer['O'])) {
            $parts[] = 'O=' . $issuer['O'];
        }
        if (isset($issuer['C'])) {
            $parts[] = 'C=' . $issuer['C'];
        }

        return implode(', ', $parts);
    }

    /**
     * Get signature value from signed XML (for QR code).
     */
    public function extractSignature(string $signedXml): ?string
    {
        $dom = new DOMDocument();
        $dom->loadXML($signedXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS_NS);

        $nodes = $xpath->query('//ds:SignatureValue');

        if ($nodes->length > 0) {
            return $nodes->item(0)->textContent;
        }

        return null;
    }

    /**
     * Verify signature in signed XML.
     */
    public function verify(string $signedXml): bool
    {
        // Implementation would verify the signature
        // For now, return true if signature exists
        return $this->extractSignature($signedXml) !== null;
    }
}
