<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Domains\Compliance\Fatoora\Support\XadesDigest;
use DOMDocument;
use DOMElement;
use phpseclib3\Math\BigInteger;

/**
 * The ds:Object an invoice signature carries: xades:QualifyingProperties and
 * the SignedProperties block inside it.
 *
 * Every byte of that block is digested, by XadesSigner here and by ZATCA when
 * it checks the document, so how each element is written is part of the
 * signature rather than presentation.
 */
class SignedPropertiesBuilder
{
    /**
     * Create XAdES Object with SignedProperties.
     *
     * @param  DOMDocument  $dom  XML document
     * @param  string  $signatureId  Dynamic signature ID (for Target attribute)
     * @param  string  $signedPropertiesId  SignedProperties element ID
     * @param  string  $certificatePem  Certificate PEM
     * @return array{object: DOMElement, signedProperties: DOMElement}
     */
    public function build(DOMDocument $dom, string $signatureId, string $signedPropertiesId, string $certificatePem): array
    {
        $object = $dom->createElementNS(XadesDigest::DS_NS, 'ds:Object');

        // Target names the signature without a leading '#'.
        //
        // XML-DSig defines Target as a URI reference, so '#'+Id is the correct
        // reading and is what this wrote first. The authority's own documents
        // write the bare Id and its validator compares the two literally, so a
        // conformant '#signature' matches nothing it looks for. Established by
        // submitting ZATCA's own sample simplified invoice, which the live API
        // accepts, and diffing it against ours: Target="signature" there,
        // Target="#signature-<random>" here. Like the Id in XadesSigner, this
        // was not what the authority was refusing - it is simply what it writes.
        $qualifyingProps = $dom->createElementNS(XadesDigest::XADES_NS, 'xades:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', $signatureId);

        // From here down, createElement with the prefix written into the name
        // rather than createElementNS - which looks like a mistake and is not.
        //
        // This element's bytes are digested, and ZATCA digests it as the
        // document carries it. createElementNS attaches a namespace
        // declaration to each element it makes, and libxml keeps them when the
        // subtree is assembled, so every element here carried an xmlns:ds that
        // the authority's own block does not: ten declarations against none.
        // The digest was computed over the right rule and the wrong bytes, and
        // every simplified document was refused for it.
        //
        // Both prefixes are already in scope - ds from ds:Signature, xades
        // from xades:QualifyingProperties above - so a literal prefixed name
        // serialises correctly and declares nothing. The XPath queries in the
        // signer and verifier all target QualifyingProperties and above, which
        // are still built namespace-aware, so nothing looks for these by
        // namespace.
        //
        // Checked against the authority: its SDK signing this platform's own
        // invoice produces a block with no declarations inside it, and with
        // this change ours matches, so xadesSignedPropertiesDigestValue passes.
        $signedProps = $dom->createElement('xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropertiesId);

        // SignedSignatureProperties
        $signedSigProps = $dom->createElement('xades:SignedSignatureProperties');

        // SigningTime, in the form the authority signs with: local time, no
        // timezone designator.
        //
        // This carried a trailing Z, and a comment here defended it on the
        // grounds that a bare stamp is ambiguous and the SDK's format was not
        // evidence of what ZATCA reads. The authority settled it: every
        // simplified document was refused with "Invalid signed properties
        // hashing", and the Z was the last difference between this block and
        // the one the SDK produces for the same invoice.
        //
        // The SDK could not have told us. It recomputes the digest from the
        // bytes it is handed, so both forms hash consistently to it - which is
        // why all 26 conformance tests passed while the live API refused the
        // documents. A validator that reads what you wrote cannot catch a
        // disagreement about what to write.
        //
        // The Kingdom's clock, so that an unmarked stamp read as local time is
        // right, and so this agrees with IssueTime and the QR.
        $signingTime = $dom->createElement('xades:SigningTime', FatooraTime::signingTime());
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
        $signingCert = $dom->createElement('xades:SigningCertificate');
        $cert = $dom->createElement('xades:Cert');

        // CertDigest
        $certDigest = $dom->createElement('xades:CertDigest');

        $digestMethod = $dom->createElement('ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigest->appendChild($digestMethod);

        // Two things about this digest, both of them unlike every other digest
        // in this signature, and both taken from what ZATCA's own signed
        // samples carry rather than from what XML-DSig would suggest.
        //
        // It is taken over the certificate's base64 text and not over the
        // bytes that text encodes; and it is written as hex and then base64'd,
        // so the value decodes to sixty-four hex characters rather than to
        // thirty-two bytes.
        //
        // Sent either other way the certificate reads as the wrong one, and
        // the signed properties digest and the signature over them are wrong
        // with it, because this value sits inside them.
        $digestValue = $dom->createElement(
            'ds:DigestValue',
            base64_encode(hash('sha256', XadesDigest::certificateText($certificatePem)))
        );
        $certDigest->appendChild($digestValue);

        $cert->appendChild($certDigest);

        // IssuerSerial
        $issuerSerial = $dom->createElement('xades:IssuerSerial');

        $certInfo = openssl_x509_parse($certificatePem);
        $issuerName = $dom->createElement('ds:X509IssuerName', $this->formatIssuerName($certInfo['issuer'] ?? []));
        $issuerSerial->appendChild($issuerName);

        // The schema types X509SerialNumber as an integer, but OpenSSL prints a
        // serial of 32 bits or more as 0x-prefixed hex, and every ZATCA-issued
        // certificate's serial is that long. The hex form is always present,
        // so the decimal is built from it.
        $serial = isset($certInfo['serialNumberHex'])
            ? (new BigInteger($certInfo['serialNumberHex'], 16))->toString()
            : '';
        $serialNumber = $dom->createElement('ds:X509SerialNumber', $serial);
        $issuerSerial->appendChild($serialNumber);

        $cert->appendChild($issuerSerial);
        $signingCert->appendChild($cert);

        return $signingCert;
    }

    /**
     * The issuer's whole distinguished name, as X509IssuerName must carry it.
     *
     * This named CN, O and C and dropped everything else, which is every
     * field a self-signed certificate made here happens to have and not the
     * ones a real CSID has: ZATCA's own CA is CN plus three DC components and
     * no O or C at all, so the name came out as the CN alone. The name is
     * inside the signed properties, so a short one takes their digest and the
     * signature over them with it - which is why a document refused for
     * "wrong X509IssuerName" is refused three more times for the digests
     * computed over it.
     *
     * Written most specific first, which is the order the string form of a
     * distinguished name reverses the certificate into, and every component
     * is kept including the repeated ones.
     *
     * @param  array<string, string|list<string>>  $issuer
     */
    private function formatIssuerName(array $issuer): string
    {
        $parts = [];

        foreach ($issuer as $field => $value) {
            foreach ((array) $value as $component) {
                $parts[] = $field.'='.$component;
            }
        }

        return implode(', ', array_reverse($parts));
    }
}
