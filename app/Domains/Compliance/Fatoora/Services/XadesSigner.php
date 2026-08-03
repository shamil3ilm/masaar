<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Exceptions\SigningException;
use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Support\Xml;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * XAdES-BES/XAdES-T digital signature service.
 *
 * Implements XML Advanced Electronic Signatures (XAdES) for ZATCA compliance.
 * Creates enveloped signatures embedded within the invoice XML.
 * Supports optional timestamp authority (TSA) for XAdES-T signatures.
 *
 * @see ETSI EN 319 132-1 (XAdES specification)
 */
class XadesSigner
{
    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';
    private const C14N_NS = 'http://www.w3.org/2006/12/xml-c14n11';

    private ?string $tsaUrl = null;
    private ?string $tsaUsername = null;
    private ?string $tsaPassword = null;
    private int $tsaTimeout;

    /**
     * Get TSA timeout from config.
     */
    private function getDefaultTsaTimeout(): int
    {
        return (int) config('fatoora.tsa.timeout', 30);
    }

    public function __construct(
        private readonly EcdsaSigner $ecdsaSigner,
        private readonly CertificateService $certificateService,
    ) {
        $this->tsaTimeout = $this->getDefaultTsaTimeout();
    }

    /**
     * Configure Timestamp Authority (TSA) for XAdES-T signatures.
     *
     * @param string $url TSA server URL (RFC 3161)
     * @param string|null $username Optional authentication username
     * @param string|null $password Optional authentication password
     * @param int $timeout Request timeout in seconds
     * @return self
     */
    public function withTimestampAuthority(
        string $url,
        ?string $username = null,
        ?string $password = null,
        ?int $timeout = null
    ): self {
        $this->tsaUrl = $url;
        $this->tsaUsername = $username;
        $this->tsaPassword = $password;
        $this->tsaTimeout = $timeout ?? $this->getDefaultTsaTimeout();

        return $this;
    }

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
        Xml::load($dom, $xml);

        // Generate signature ID
        $signatureId = 'signature-' . bin2hex(random_bytes(8));
        $signedPropertiesId = 'signedprops-' . bin2hex(random_bytes(8));

        // Create Signature element
        $signature = $this->createSignatureElement($dom, $signatureId);

        // Create XAdES Object first (need SignedProperties for digest calculation)
        $xadesResult = $this->createXadesObject($dom, $signatureId, $signedPropertiesId, $certificatePem);
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

        // DigestValue - apply transforms before calculating digest
        $transformedXml = $this->applyDocumentTransforms($xml);
        $digest = base64_encode(hash('sha256', $transformedXml, true));
        $digestValue = $dom->createElementNS(self::DS_NS, 'ds:DigestValue', $digest);
        $reference->appendChild($digestValue);

        return $reference;
    }

    /**
     * Apply transforms to document for digest calculation.
     *
     * Per XML-DSIG, digest must be calculated on transformed data:
     * 1. Enveloped signature transform (remove ds:Signature) - N/A before signing
     * 2. XPath transform (exclude UBLExtensions)
     * 3. Canonicalization (C14N 1.1)
     */
    private function applyDocumentTransforms(string $xml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        Xml::load($dom, $xml);

        // Apply XPath transform: exclude UBLExtensions
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        $extensions = $xpath->query('//ext:UBLExtensions');
        foreach ($extensions as $extension) {
            $extension->parentNode->removeChild($extension);
        }

        // Apply C14N canonicalization
        return $dom->documentElement->C14N(true, false);
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
     * @param DOMDocument $dom XML document
     * @param string $signatureId Dynamic signature ID (for Target attribute)
     * @param string $signedPropertiesId SignedProperties element ID
     * @param string $certificatePem Certificate PEM
     * @return array{object: DOMElement, signedProperties: DOMElement}
     */
    private function createXadesObject(DOMDocument $dom, string $signatureId, string $signedPropertiesId, string $certificatePem): array
    {
        $object = $dom->createElementNS(self::DS_NS, 'ds:Object');

        // QualifyingProperties - Target must reference the actual signature ID
        $qualifyingProps = $dom->createElementNS(self::XADES_NS, 'xades:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', '#' . $signatureId);

        // SignedProperties
        $signedProps = $dom->createElementNS(self::XADES_NS, 'xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropertiesId);

        // SignedSignatureProperties
        $signedSigProps = $dom->createElementNS(self::XADES_NS, 'xades:SignedSignatureProperties');

        // SigningTime (must be UTC per ZATCA requirements)
        $signingTime = $dom->createElementNS(self::XADES_NS, 'xades:SigningTime', FatooraTime::nowFormatted());
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
        Xml::load($dom, $signedXml);

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
     *
     * @param string $signedXml Signed XML document
     * @return bool True if signature is valid
     */
    public function verify(string $signedXml): bool
    {
        try {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            Xml::load($dom, $signedXml);

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('ds', self::DS_NS);

            // Get SignedInfo element
            $signedInfoNodes = $xpath->query('//ds:SignedInfo');
            if ($signedInfoNodes->length === 0) {
                return false;
            }
            $signedInfo = $signedInfoNodes->item(0);

            // Get SignatureValue
            $signatureValueNodes = $xpath->query('//ds:SignatureValue');
            if ($signatureValueNodes->length === 0) {
                return false;
            }
            $signatureValue = $signatureValueNodes->item(0)->textContent;

            // Get certificate from KeyInfo
            $certNodes = $xpath->query('//ds:X509Certificate');
            if ($certNodes->length === 0) {
                return false;
            }
            $certBase64 = $certNodes->item(0)->textContent;
            $certificatePem = "-----BEGIN CERTIFICATE-----\n" .
                chunk_split($certBase64, 64, "\n") .
                "-----END CERTIFICATE-----";

            // Canonicalize SignedInfo
            $signedInfoC14n = $signedInfo->C14N(true, false);

            // Verify the signature
            return $this->ecdsaSigner->verify(
                $signedInfoC14n,
                $signatureValue,
                $this->ecdsaSigner->extractPublicKey($certificatePem)
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify all references in the signature.
     *
     * @param string $signedXml Signed XML document
     * @return array{valid: bool, errors: array}
     */
    public function verifyReferences(string $signedXml): array
    {
        $errors = [];

        try {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            Xml::load($dom, $signedXml);

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('ds', self::DS_NS);

            // Get all Reference elements
            $references = $xpath->query('//ds:Reference');

            foreach ($references as $reference) {
                $uri = $reference->getAttribute('URI');
                $type = $reference->getAttribute('Type');

                // Get expected digest
                $digestValueNodes = $xpath->query('ds:DigestValue', $reference);
                if ($digestValueNodes->length === 0) {
                    $errors[] = "Reference {$uri}: missing DigestValue";
                    continue;
                }
                $expectedDigest = $digestValueNodes->item(0)->textContent;

                // Calculate actual digest based on reference type
                if ($type === 'http://uri.etsi.org/01903#SignedProperties') {
                    // Reference to SignedProperties
                    $targetId = ltrim($uri, '#');
                    $targetNodes = $xpath->query("//*[@Id='{$targetId}']");
                    if ($targetNodes->length === 0) {
                        $errors[] = "Reference {$uri}: target element not found";
                        continue;
                    }
                    $target = $targetNodes->item(0);
                    $targetC14n = $target->C14N(true, false);
                    $actualDigest = base64_encode(hash('sha256', $targetC14n, true));
                } elseif (empty($uri)) {
                    // Reference to document (enveloped signature)
                    // Apply transforms: remove signature, remove UBLExtensions, canonicalize
                    $actualDigest = $this->calculateDocumentReferenceDigest($dom);
                } else {
                    continue;
                }

                if ($actualDigest !== $expectedDigest) {
                    $errors[] = "Reference {$uri}: digest mismatch";
                }
            }
        } catch (\Exception $e) {
            $errors[] = 'Verification error: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Calculate document reference digest for verification.
     *
     * Applies the same transforms as signing:
     * 1. Remove ds:Signature (enveloped-signature)
     * 2. Remove UBLExtensions (XPath)
     * 3. Canonicalize (C14N 1.1)
     */
    private function calculateDocumentReferenceDigest(DOMDocument $dom): string
    {
        // Clone to avoid modifying original
        $clone = $dom->cloneNode(true);

        $xpath = new DOMXPath($clone);
        $xpath->registerNamespace('ds', self::DS_NS);
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        // Remove ds:Signature (enveloped-signature transform)
        $signatures = $xpath->query('//ds:Signature');
        foreach ($signatures as $sig) {
            $sig->parentNode->removeChild($sig);
        }

        // Remove UBLExtensions (XPath transform)
        $extensions = $xpath->query('//ext:UBLExtensions');
        foreach ($extensions as $ext) {
            $ext->parentNode->removeChild($ext);
        }

        // Canonicalize and hash
        $canonicalized = $clone->documentElement->C14N(true, false);

        return base64_encode(hash('sha256', $canonicalized, true));
    }

    /**
     * Sign invoice XML with XAdES-T (timestamped) signature.
     *
     * Creates an XAdES-BES signature and adds a timestamp token from a TSA.
     * Requires TSA to be configured via withTimestampAuthority().
     *
     * @param string $xml Invoice XML document
     * @param string $privateKeyPem Private key for signing
     * @param string $certificatePem X.509 certificate
     * @return string Signed and timestamped XML document
     * @throws SigningException
     */
    public function signWithTimestamp(string $xml, string $privateKeyPem, string $certificatePem): string
    {
        if ($this->tsaUrl === null) {
            throw new SigningException('Timestamp authority not configured. Call withTimestampAuthority() first.');
        }

        // First create normal XAdES-BES signature
        $signedXml = $this->sign($xml, $privateKeyPem, $certificatePem);

        // Add timestamp to the signature
        return $this->addSignatureTimestamp($signedXml);
    }

    /**
     * Add timestamp to an existing XAdES-BES signature (upgrade to XAdES-T).
     *
     * @param string $signedXml Signed XML with XAdES-BES signature
     * @return string XML with XAdES-T signature (includes SignatureTimeStamp)
     * @throws SigningException
     */
    public function addSignatureTimestamp(string $signedXml): string
    {
        if ($this->tsaUrl === null) {
            throw new SigningException('Timestamp authority not configured');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        Xml::load($dom, $signedXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::DS_NS);
        $xpath->registerNamespace('xades', self::XADES_NS);

        // Get SignatureValue to timestamp
        $signatureValueNodes = $xpath->query('//ds:SignatureValue');
        if ($signatureValueNodes->length === 0) {
            throw new SigningException('No signature found to timestamp');
        }

        $signatureValue = $signatureValueNodes->item(0);
        $signatureValueC14n = $signatureValue->C14N(true, false);

        // Request timestamp from TSA
        $timestampToken = $this->requestTimestamp($signatureValueC14n);

        // Find or create UnsignedProperties in QualifyingProperties
        $qualifyingProps = $xpath->query('//xades:QualifyingProperties')->item(0);
        if ($qualifyingProps === null) {
            throw new SigningException('QualifyingProperties not found');
        }

        $unsignedProps = $xpath->query('xades:UnsignedProperties', $qualifyingProps)->item(0);
        if ($unsignedProps === null) {
            $unsignedProps = $dom->createElementNS(self::XADES_NS, 'xades:UnsignedProperties');
            $qualifyingProps->appendChild($unsignedProps);
        }

        // Create UnsignedSignatureProperties
        $unsignedSigProps = $xpath->query('xades:UnsignedSignatureProperties', $unsignedProps)->item(0);
        if ($unsignedSigProps === null) {
            $unsignedSigProps = $dom->createElementNS(self::XADES_NS, 'xades:UnsignedSignatureProperties');
            $unsignedProps->appendChild($unsignedSigProps);
        }

        // Create SignatureTimeStamp element
        $sigTimeStamp = $dom->createElementNS(self::XADES_NS, 'xades:SignatureTimeStamp');
        $sigTimeStamp->setAttribute('Id', 'timestamp-' . bin2hex(random_bytes(8)));

        // CanonicalizationMethod
        $c14nMethod = $dom->createElementNS(self::DS_NS, 'ds:CanonicalizationMethod');
        $c14nMethod->setAttribute('Algorithm', self::C14N_NS);
        $sigTimeStamp->appendChild($c14nMethod);

        // EncapsulatedTimeStamp (base64-encoded timestamp token)
        $encapsulatedTS = $dom->createElementNS(self::XADES_NS, 'xades:EncapsulatedTimeStamp');
        $encapsulatedTS->textContent = base64_encode($timestampToken);
        $sigTimeStamp->appendChild($encapsulatedTS);

        $unsignedSigProps->appendChild($sigTimeStamp);

        $dom->formatOutput = true;

        return $dom->saveXML();
    }

    /**
     * Request timestamp from TSA (RFC 3161).
     *
     * @param string $data Data to timestamp (will be SHA-256 hashed)
     * @return string Binary timestamp token
     * @throws SigningException
     */
    private function requestTimestamp(string $data): string
    {
        // Create timestamp request (RFC 3161)
        $digest = hash('sha256', $data, true);
        $tsRequest = $this->createTimestampRequest($digest);

        // Send request to TSA
        $ch = curl_init();

        $headers = [
            'Content-Type: application/timestamp-query',
            'Accept: application/timestamp-reply',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->tsaUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $tsRequest,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->tsaTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Add authentication if configured
        if ($this->tsaUsername !== null && $this->tsaPassword !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->tsaUsername . ':' . $this->tsaPassword);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new SigningException("TSA request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new SigningException("TSA returned HTTP {$httpCode}");
        }

        // Parse and validate timestamp response
        $timestampToken = $this->parseTimestampResponse($response);

        if ($timestampToken === null) {
            throw new SigningException('Invalid timestamp response from TSA');
        }

        return $timestampToken;
    }

    /**
     * Create RFC 3161 Timestamp Request.
     *
     * @param string $digest SHA-256 hash (binary)
     * @return string DER-encoded timestamp request
     */
    private function createTimestampRequest(string $digest): string
    {
        // TimeStampReq ::= SEQUENCE {
        //    version         INTEGER { v1(1) },
        //    messageImprint  MessageImprint,
        //    reqPolicy       TSAPolicyId OPTIONAL,
        //    nonce           INTEGER OPTIONAL,
        //    certReq         BOOLEAN DEFAULT FALSE,
        //    extensions      [0] IMPLICIT Extensions OPTIONAL
        // }
        //
        // MessageImprint ::= SEQUENCE {
        //    hashAlgorithm   AlgorithmIdentifier,
        //    hashedMessage   OCTET STRING
        // }

        // SHA-256 OID: 2.16.840.1.101.3.4.2.1
        $sha256Oid = "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";

        // Build AlgorithmIdentifier (SEQUENCE with OID and NULL)
        $algorithmIdentifier = "\x30" . chr(strlen($sha256Oid) + 2) . $sha256Oid . "\x05\x00";

        // Build hashedMessage (OCTET STRING)
        $hashedMessage = "\x04" . chr(strlen($digest)) . $digest;

        // Build MessageImprint (SEQUENCE)
        $messageImprintContent = $algorithmIdentifier . $hashedMessage;
        $messageImprint = "\x30" . $this->asn1Length(strlen($messageImprintContent)) . $messageImprintContent;

        // Build version INTEGER (1)
        $version = "\x02\x01\x01";

        // Generate nonce
        $nonceBytes = random_bytes(8);
        $nonceContent = ltrim($nonceBytes, "\x00") ?: "\x00";
        if (ord($nonceContent[0]) & 0x80) {
            $nonceContent = "\x00" . $nonceContent;
        }
        $nonce = "\x02" . chr(strlen($nonceContent)) . $nonceContent;

        // certReq BOOLEAN TRUE
        $certReq = "\x01\x01\xff";

        // Build TimeStampReq (SEQUENCE)
        $tsReqContent = $version . $messageImprint . $nonce . $certReq;
        $tsReq = "\x30" . $this->asn1Length(strlen($tsReqContent)) . $tsReqContent;

        return $tsReq;
    }

    /**
     * Encode ASN.1 length.
     */
    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xff) . $bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Parse RFC 3161 Timestamp Response.
     *
     * @param string $response Binary timestamp response
     * @return string|null Timestamp token or null if invalid
     */
    private function parseTimestampResponse(string $response): ?string
    {
        // TimeStampResp ::= SEQUENCE {
        //    status          PKIStatusInfo,
        //    timeStampToken  TimeStampToken OPTIONAL
        // }
        //
        // PKIStatusInfo ::= SEQUENCE {
        //    status        PKIStatus,
        //    statusString  PKIFreeText OPTIONAL,
        //    failInfo      PKIFailureInfo OPTIONAL
        // }
        //
        // PKIStatus ::= INTEGER {
        //    granted                (0),
        //    grantedWithMods        (1),
        //    rejection              (2),
        //    waiting                (3),
        //    revocationWarning      (4),
        //    revocationNotification (5)
        // }

        if (strlen($response) < 10) {
            return null;
        }

        // Check for SEQUENCE tag
        if (ord($response[0]) !== 0x30) {
            return null;
        }

        $offset = 1;
        $totalLength = $this->readAsn1Length($response, $offset);
        $offset = $this->skipAsn1Length($response, 1);

        // Read PKIStatusInfo SEQUENCE
        if (ord($response[$offset]) !== 0x30) {
            return null;
        }
        $offset++;
        $statusInfoLength = $this->readAsn1Length($response, $offset);
        $offset = $this->skipAsn1Length($response, $offset);

        // Read status INTEGER
        if (ord($response[$offset]) !== 0x02) {
            return null;
        }
        $offset++;
        $statusLength = $this->readAsn1Length($response, $offset);
        $offset = $this->skipAsn1Length($response, $offset);

        $status = 0;
        for ($i = 0; $i < $statusLength; $i++) {
            $status = ($status << 8) | ord($response[$offset + $i]);
        }

        // Check status (0 = granted, 1 = grantedWithMods)
        if ($status > 1) {
            \Log::warning('TSA returned non-granted status', ['status' => $status]);
            return null;
        }

        // Skip to timeStampToken
        $offset += $statusLength;

        // Skip any optional statusString or failInfo
        while ($offset < strlen($response) && ord($response[$offset]) !== 0x30) {
            $tag = ord($response[$offset]);
            $offset++;
            $len = $this->readAsn1Length($response, $offset);
            $offset = $this->skipAsn1Length($response, $offset);
            $offset += $len;
        }

        // timeStampToken is ContentInfo SEQUENCE
        if ($offset >= strlen($response) || ord($response[$offset]) !== 0x30) {
            return null;
        }

        // Return the entire timeStampToken
        $tokenStart = $offset;
        $offset++;
        $tokenLength = $this->readAsn1Length($response, $offset);
        $offset = $this->skipAsn1Length($response, $offset);

        return substr($response, $tokenStart, ($offset - $tokenStart) + $tokenLength);
    }

    /**
     * Read ASN.1 length from data.
     */
    private function readAsn1Length(string $data, int $offset): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            return $byte;
        }

        $numBytes = $byte & 0x7F;
        $length = 0;

        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($data[$offset + 1 + $i]);
        }

        return $length;
    }

    /**
     * Skip ASN.1 length field.
     */
    private function skipAsn1Length(string $data, int $offset): int
    {
        $byte = ord($data[$offset]);

        if ($byte < 0x80) {
            return $offset + 1;
        }

        return $offset + 1 + ($byte & 0x7F);
    }

    /**
     * Verify timestamp on a signed document.
     *
     * @param string $signedXml Signed XML with timestamp
     * @return array{valid: bool, timestamp: string|null, tsaName: string|null}
     */
    public function verifyTimestamp(string $signedXml): array
    {
        try {
            $dom = new DOMDocument();
            Xml::load($dom, $signedXml);

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('xades', self::XADES_NS);

            // Find EncapsulatedTimeStamp
            $timestampNodes = $xpath->query('//xades:EncapsulatedTimeStamp');
            if ($timestampNodes->length === 0) {
                return [
                    'valid' => false,
                    'timestamp' => null,
                    'tsaName' => null,
                    'error' => 'No timestamp found',
                ];
            }

            $timestampB64 = $timestampNodes->item(0)->textContent;
            $timestampToken = base64_decode($timestampB64);

            // Parse timestamp to extract time (basic parsing)
            // Full verification would require OpenSSL or dedicated TSP library
            $timestampFile = tempnam(sys_get_temp_dir(), 'ts_');
            file_put_contents($timestampFile, $timestampToken);

            try {
                $output = shell_exec(sprintf(
                    'openssl ts -reply -in %s -token_in -text 2>&1',
                    escapeshellarg($timestampFile)
                ));

                if ($output === null) {
                    return [
                        'valid' => false,
                        'timestamp' => null,
                        'tsaName' => null,
                        'error' => 'Could not parse timestamp',
                    ];
                }

                $timestamp = null;
                $tsaName = null;

                if (preg_match('/Time stamp:\s*(.+)/i', $output, $matches)) {
                    $timestamp = trim($matches[1]);
                }

                if (preg_match('/TSA:\s*(.+)/i', $output, $matches)) {
                    $tsaName = trim($matches[1]);
                }

                return [
                    'valid' => true,
                    'timestamp' => $timestamp,
                    'tsaName' => $tsaName,
                ];
            } finally {
                unlink($timestampFile);
            }
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'timestamp' => null,
                'tsaName' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
