<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Signing;

use App\Support\Xml;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Checks an enveloped XAdES signature the way XadesSigner makes one.
 *
 * Two questions, and a verifier needs both answered to conclude anything:
 * verify() asks whether ds:SignedInfo was altered after signing, and
 * verifyReferences() asks whether the document is still the one that was
 * signed.
 */
class XadesVerifier
{
    private const DS_NS = XadesDigest::DS_NS;

    public function __construct(
        private readonly EcdsaSigner $ecdsaSigner,
    ) {}

    /**
     * Verify signature in signed XML.
     *
     * @param  string  $signedXml  Signed XML document
     * @return bool True if signature is valid
     */
    public function verify(string $signedXml): bool
    {
        try {
            $dom = new DOMDocument;
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
            $certificatePem = CertificatePem::from($certBase64) ?? '';

            // Canonicalize SignedInfo
            $signedInfoC14n = $signedInfo->C14N(false, false);

            // Verify the signature
            return $this->ecdsaSigner->verify(
                $signedInfoC14n,
                $signatureValue,
                $this->ecdsaSigner->extractPublicKey($certificatePem)
            );
        } catch (\Exception $e) {
            Log::warning('Signature verification could not run', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify all references in the signature.
     *
     * @param  string  $signedXml  Signed XML document
     * @return array{valid: bool, errors: array}
     */
    public function verifyReferences(string $signedXml): array
    {
        $errors = [];

        try {
            $dom = new DOMDocument;
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
                // Matched on what the reference points at: the type for this
                // one is not the XAdES type and has been spelt both ways.
                if (str_ends_with($uri, 'xadesSignedProperties') || str_contains($type, 'SignedProperties')) {
                    // Reference to SignedProperties
                    $targetId = ltrim($uri, '#');
                    $targetNodes = $xpath->query("//*[@Id='{$targetId}']");
                    if ($targetNodes->length === 0) {
                        $errors[] = "Reference {$uri}: target element not found";

                        continue;
                    }
                    $target = $targetNodes->item(0);
                    // Hex then base64, matching how this one reference is
                    // written, and over the same reconstruction the signer
                    // digests. The document's own reference below is the
                    // base64 of the digest's bytes, and stays that way.
                    $actualDigest = base64_encode(hash('sha256', XadesDigest::signedPropertiesInput($target)));
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
            $errors[] = 'Verification error: '.$e->getMessage();
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

        // The same exclusions the signature declares and signing applied.
        XadesDigest::exclude($clone);

        // Canonicalize and hash
        $canonicalized = $clone->documentElement->C14N(false, false);

        return base64_encode(hash('sha256', $canonicalized, true));
    }
}
