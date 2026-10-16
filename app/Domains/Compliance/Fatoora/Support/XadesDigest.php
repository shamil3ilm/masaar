<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * What an invoice signature digests, stated once for the signer and the
 * verifier.
 *
 * Both have to reach the same bytes from the same document, and when each
 * kept its own copy of these rules they disagreed: the verifier removed
 * UBLExtensions and ds:Signature only, so once cac:Signature and the QR were
 * in the document it reported a valid signature as invalid.
 */
final class XadesDigest
{
    public const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N_NS = 'http://www.w3.org/2006/12/xml-c14n11';

    public const EXT_NS = Ubl::EXT_NS;

    public const CAC_NS = Ubl::CAC_NS;

    public const CBC_NS = Ubl::CBC_NS;

    /**
     * What the invoice signature does not cover, as XPath and as the
     * expressions ZATCA's own documents declare in ds:Transforms.
     *
     * The two must agree, so they are stated once. Keys are what a verifier is
     * told; values find the same nodes in a DOMDocument.
     */
    public const EXCLUDED = [
        'not(//ancestor-or-self::ext:UBLExtensions)' => '//ext:UBLExtensions',
        'not(//ancestor-or-self::cac:Signature)' => '//cac:Signature',
        "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])" => "//cac:AdditionalDocumentReference[cbc:ID='QR']",
    ];

    /**
     * Take out of a document everything the signature does not cover.
     *
     * Three things, and only UBLExtensions was being removed. The other two
     * matter because both are written after the digest is taken:
     *
     * - cac:Signature says the invoice is signed and how, which cannot be
     *   known while signing it.
     * - The QR AdditionalDocumentReference carries the signature itself, so it
     *   is added once there is one.
     *
     * Signing over either would mean a document that fails its own digest the
     * moment it is finished. It did not show while the QR was never inserted
     * and cac:Signature was never emitted; with both present, every signature
     * this platform produced would have failed verification.
     *
     * Whatever is removed here must also be named in the ds:Transforms the
     * signature declares, or a verifier applying what we declared reaches a
     * different digest than we did.
     */
    public static function exclude(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', self::EXT_NS);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);
        $xpath->registerNamespace('ds', self::DS_NS);

        foreach (self::EXCLUDED as $path) {
            foreach (iterator_to_array($xpath->query($path)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /**
     * The bytes ZATCA digests for the SignedProperties reference.
     *
     * The element exactly as this document carries it, and nothing else. The
     * validator pulls the element out of the document and digests what it
     * finds, which was established by compacting that element in one of the
     * authority's own samples, recomputing the digest over the compacted
     * bytes, and watching the check still pass: the formatting is the
     * document's to choose, and the digest has to describe it.
     *
     * Nothing is added here because nothing is missing. Building these
     * elements with createElementNS declares xmlns:ds on each one that needs
     * it rather than once on an ancestor, so the subtree is already
     * self-contained and serialising it alone changes none of its bytes.
     * Stripping those declarations and putting them back where ZATCA's own
     * samples carry them produced a digest of a document nobody had.
     */
    public static function signedPropertiesInput(DOMElement $signedProperties): string
    {
        $xml = (string) $signedProperties->ownerDocument?->saveXML($signedProperties);

        // The element as the document carries it, with xmlns:xades written on
        // it and xmlns:ds written on each ds: child that lacks one, and
        // nothing removed.
        //
        // This is the rule the authority's validator uses, confirmed twice:
        // the digest it records for its own sample is the SHA-256 of exactly
        // this reconstruction of that sample's block, and so is the digest it
        // records when its SDK signs a document generated here.
        //
        // It agrees for the documents the signer produces, since the block
        // stopped carrying a namespace declaration on every element - see
        // SignedPropertiesBuilder::build. A declaration is not data a reader
        // keeps: it puts the prefix in scope and writes it back where it is
        // needed, which is why the block has to be built with none inside it
        // and why these two are added back here rather than left to the
        // serialiser.
        if (! str_contains((string) strstr($xml, '>', true), 'xmlns:xades=')) {
            $xml = (string) preg_replace(
                '#^<xades:SignedProperties#',
                '<xades:SignedProperties xmlns:xades="'.self::XADES_NS.'"',
                $xml,
                1
            );
        }

        return (string) preg_replace(
            '#<ds:([A-Za-z0-9]+)(?![^>]*xmlns:ds=)#',
            '<ds:$1 xmlns:ds="'.self::DS_NS.'"',
            $xml
        );
    }

    /**
     * A certificate's base64 text, without its PEM headers.
     *
     * What ds:X509Certificate carries, and what the signing certificate's
     * digest is taken over - the text, not the bytes it encodes.
     */
    public static function certificateText(string $pem): string
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
}
