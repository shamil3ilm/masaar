<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Domains\Compliance\Countries\SaudiArabia\Document\Ubl;
use App\Domains\Compliance\Countries\SaudiArabia\Document\XmlBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\SigningException;
use App\Support\Xml;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * XAdES digital signature service, at baseline level B-B.
 *
 * Implements XML Advanced Electronic Signatures (XAdES) for ZATCA compliance.
 * Creates enveloped signatures embedded within the invoice XML. There is no
 * timestamp: ZATCA's security features standard asks for B-B, which carries
 * none, and the RFC 3161 client that once sat here was never called.
 *
 * The signed properties block is SignedPropertiesBuilder's, what is digested
 * is XadesDigest's, and checking a signature is XadesVerifier's.
 *
 * @see ETSI EN 319 132-1 (XAdES specification)
 */
class XadesSigner
{
    private const DS_NS = XadesDigest::DS_NS;

    private const C14N_NS = XadesDigest::C14N_NS;

    /**
     * The UBL signature envelope BR-KSA-28 reads.
     */
    private const SIG_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    private const SAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    private const SBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    private const CBC_NS = XadesDigest::CBC_NS;

    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    public function __construct(
        private readonly EcdsaSigner $ecdsaSigner,
        private readonly CertificateService $certificateService,
        private readonly SignedPropertiesBuilder $signedProperties,
    ) {}

    /**
     * Sign invoice XML with XAdES-BES signature.
     *
     * @param  string  $xml  Invoice XML document
     * @param  string  $privateKeyPem  Private key for signing
     * @param  string  $certificatePem  X.509 certificate
     * @return string Signed XML document
     *
     * @throws SigningException
     */
    public function sign(string $xml, string $privateKeyPem, string $certificatePem, ?string $environment = null): string
    {
        $this->assertKeyMatchesCertificate($privateKeyPem, $certificatePem, $environment);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        Xml::load($dom, $xml);

        // Both fixed, not generated, for the same reason: ZATCA rebuilds this
        // block from a template of its own to check the digest, and that
        // template carries these two values. A unique Id per signature changes
        // the bytes it hashes and nothing else, so the digest never agrees.
        //
        // The signature's own Id was generated for some time. Fixing it did
        // not make the authority accept anything - that was formatOutput on
        // the QR round trip, see QrCodeInjector - so this is not the
        // cure for a refusal, and nobody should read it as one. It is here
        // because the authority's own accepted documents carry
        // Id="signature", and a value it reconstructs is not ours to choose.
        $signatureId = 'signature';
        $signedPropertiesId = 'xadesSignedProperties';

        $signature = $this->createSignatureElement($dom, $signatureId);

        $xadesResult = $this->signedProperties->build($dom, $signatureId, $signedPropertiesId, $certificatePem);
        $object = $xadesResult['object'];
        $signedProperties = $xadesResult['signedProperties'];

        // Everything is put into the document before anything is canonicalised.
        // DOMNode::C14N() returns the empty string for a subtree that is not
        // attached, and says nothing about it — so building SignedInfo first
        // and signing it before insertion signed the empty string, and the
        // SignedProperties reference carried the digest of the empty string.
        // Both are well-formed and neither verifies.
        $signature->appendChild($object);
        $signature = $this->insertSignature($dom, $signature);

        // XML-DSig fixes the order of a Signature's children: SignedInfo,
        // SignatureValue, KeyInfo, then Object. Each is placed before the
        // object rather than appended.
        $signedInfo = $this->createSignedInfo($dom, $xml, $signedPropertiesId, $signedProperties);
        $signature->insertBefore($signedInfo, $object);

        $signatureValue = $this->ecdsaSigner->sign(
            $this->canonicalize($signedInfo),
            $privateKeyPem
        );

        $signature->insertBefore(
            Ubl::element($dom, self::DS_NS, 'ds:SignatureValue', $signatureValue),
            $object
        );

        $signature->insertBefore($this->createKeyInfo($dom, $certificatePem), $object);

        // Deliberately not formatOutput. Pretty-printing indents the signed
        // subtrees after they were canonicalised; a verifier that reparses
        // without preserving whitespace still agrees, but one that preserves
        // it does not, and which of those ZATCA runs is not ours to assume.
        return $dom->saveXML();
    }

    /**
     * Get signature value from signed XML (for QR code).
     */
    public function extractSignature(string $signedXml): ?string
    {
        $dom = new DOMDocument;
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

        // One XPath transform per thing the signature does not cover. Only the
        // first was declared, so a verifier told to exclude UBLExtensions
        // alone would digest cac:Signature and the QR — neither of which
        // existed when the digest was taken.
        foreach (array_keys(XadesDigest::EXCLUDED) as $expression) {
            $transform = $dom->createElementNS(self::DS_NS, 'ds:Transform');
            $transform->setAttribute('Algorithm', 'http://www.w3.org/TR/1999/REC-xpath-19991116');

            $xpath = Ubl::element($dom, self::DS_NS, 'ds:XPath', $expression);
            $xpath->setAttributeNS(self::XMLNS, 'xmlns:ext', XadesDigest::EXT_NS);
            $xpath->setAttributeNS(self::XMLNS, 'xmlns:cac', XadesDigest::CAC_NS);
            $xpath->setAttributeNS(self::XMLNS, 'xmlns:cbc', self::CBC_NS);

            $transform->appendChild($xpath);
            $transforms->appendChild($transform);
        }

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
        $digestValue = Ubl::element($dom, self::DS_NS, 'ds:DigestValue', $digest);
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

        XadesDigest::exclude($dom);

        // Apply C14N canonicalization
        return $dom->documentElement->C14N(false, false);
    }

    /**
     * Create reference to SignedProperties.
     */
    private function createSignedPropertiesReference(DOMDocument $dom, string $signedPropertiesId, DOMElement $signedProperties): DOMElement
    {
        $reference = $dom->createElementNS(self::DS_NS, 'ds:Reference');
        // The type ZATCA's own signed samples carry for this reference. The
        // XAdES type for signed properties is http://uri.etsi.org/01903#
        // SignedProperties, and ZATCA does not use it here.
        $reference->setAttribute('Type', 'http://www.w3.org/2000/09/xmldsig#SignatureProperties');
        $reference->setAttribute('URI', '#'.$signedPropertiesId);

        // DigestMethod
        $digestMethod = $dom->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        // Hex then base64, the way ZATCA's own signed samples carry this one
        // reference: its value decodes to sixty-four hex characters where the
        // invoice's own reference, a few lines above, decodes to thirty-two
        // bytes. The two references in one signature do not agree with each
        // other, and this is the one that differs.
        $digest = base64_encode(hash('sha256', XadesDigest::signedPropertiesInput($signedProperties)));
        $digestValue = Ubl::element($dom, self::DS_NS, 'ds:DigestValue', $digest);
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
        $certValue = XadesDigest::certificateText($certificatePem);
        $x509Cert = Ubl::element($dom, self::DS_NS, 'ds:X509Certificate', $certValue);
        $x509Data->appendChild($x509Cert);

        $keyInfo->appendChild($x509Data);

        return $keyInfo;
    }

    /**
     * Put the signature where ZATCA looks for it, and return the node that is
     * now in the document.
     *
     * The return value is what callers must canonicalise through: C14N() on a
     * detached subtree yields the empty string, so a handle that is not the
     * node in the tree signs nothing. importNode() is dropped because the
     * element was created by this document and needs no import.
     */
    private function insertSignature(DOMDocument $dom, DOMElement $signature): DOMElement
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', XadesDigest::EXT_NS);

        $nodes = $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent');

        if ($nodes->length === 0) {
            $dom->documentElement->appendChild($signature);

            return $signature;
        }

        $extensionContent = $nodes->item(0);

        foreach (iterator_to_array($extensionContent->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $extensionContent->removeChild($child);
                break;
            }
        }

        $extensionContent->appendChild($this->envelope($dom, $signature));

        return $signature;
    }

    /**
     * The UBL signature wrapper ZATCA requires around ds:Signature.
     *
     * A ds:Signature placed straight into ext:ExtensionContent is a valid
     * enveloped signature and not what ZATCA reads. BR-KSA-28 looks for a
     * signature information ID of
     * "urn:oasis:names:specification:ubl:signature:1", which lives on
     * sac:SignatureInformation inside sig:UBLDocumentSignatures — so the
     * wrapper is what carries the ID the rule asks for. Without it every
     * document bearing a cryptographic stamp fails, which is every simplified
     * invoice.
     *
     * The digest is unaffected. The signed content excludes UBLExtensions by
     * an XPath transform, so what is built here sits outside what was signed.
     */
    private function envelope(DOMDocument $dom, DOMElement $signature): DOMElement
    {
        $signatures = $dom->createElementNS(self::SIG_NS, 'sig:UBLDocumentSignatures');
        $signatures->setAttributeNS(self::XMLNS, 'xmlns:sac', self::SAC_NS);
        $signatures->setAttributeNS(self::XMLNS, 'xmlns:sbc', self::SBC_NS);

        $information = $dom->createElementNS(self::SAC_NS, 'sac:SignatureInformation');
        $information->appendChild(
            Ubl::element($dom, self::CBC_NS, 'cbc:ID', XmlBuilder::SIGNATURE_ID)
        );
        $information->appendChild(
            Ubl::element($dom, self::SBC_NS, 'sbc:ReferencedSignatureID', XmlBuilder::SIGNATURE_REFERENCE)
        );
        $information->appendChild($signature);

        $signatures->appendChild($information);

        return $signatures;
    }

    /**
     * Canonicalize XML element.
     */
    private function canonicalize(DOMElement $element): string
    {
        return $element->C14N(false, false);
    }

    /**
     * The key that signs must be the key the certificate names.
     *
     * Nothing checked this, and nothing downstream would: the authority does
     * not verify ds:SignatureValue - established on 2026-10-10 by submitting
     * documents whose signatures had been invalidated and watching them be
     * accepted - so a mismatched pair produces invoices that are accepted at
     * submission and unverifiable ever after. That is the worst shape for a
     * defect in a compliance system: discovered by an auditor, years of
     * documents later, with nothing in the logs.
     *
     * The sandbox is exempt because it cannot comply. ZATCA's developer
     * portal issues every caller the same production certificate - byte for
     * byte the one in its own SDK sample, Data/Samples/Simplified/Invoice -
     * whose private key nobody outside the authority has. So a sandbox
     * document is signed with the taxpayer's compliance key while carrying a
     * certificate belonging to no one, and that is the sandbox working as
     * designed rather than this platform misbehaving. The warning is still
     * logged, because the fact is worth seeing in context.
     *
     * Simulation and production issue a certificate against the CSR actually
     * submitted, so there the pair must match and a mismatch is fatal.
     *
     * @throws SigningException
     */
    private function assertKeyMatchesCertificate(string $privateKeyPem, string $certificatePem, ?string $environment): void
    {
        if (openssl_x509_check_private_key($certificatePem, $privateKeyPem)) {
            return;
        }

        // The signing organization's environment (FatooraEnvironment), else
        // the deployment's.
        $environment ??= (string) config('fatoora.environment', 'sandbox');

        if ($environment === 'sandbox') {
            Log::warning('Signing with a key that does not match the certificate', [
                'environment' => $environment,
                'reason' => "ZATCA's developer sandbox issues a shared certificate whose "
                    ."private key is not the taxpayer's, so the signature in a sandbox "
                    .'document cannot be verified by anyone. Expected here; fatal elsewhere.',
            ]);

            return;
        }

        throw new SigningException(
            'The private key does not match the certificate it would be signing under, so '
            .'every invoice signed with this pair would be unverifiable. The authority does '."\n"
            .'not check this and would accept them. Re-run onboarding so the certificate is '
            .'issued against the key in use ('.$environment.').'
        );
    }
}
