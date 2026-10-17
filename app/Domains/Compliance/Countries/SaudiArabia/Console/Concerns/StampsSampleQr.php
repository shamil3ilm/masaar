<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Console\Concerns;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use RuntimeException;

/**
 * The QR fatoora:onboard puts on its signed sample documents.
 *
 * Not DocumentBuilder's: that one hashes the signed document before the QR
 * goes in, while this inserts an empty QR first and hashes the settled shape.
 * The sample chain is built on that order, so it stays here.
 *
 * Needs $hasher, $signer, $qrGenerator, $qrInjector and $certificateService.
 */
trait StampsSampleQr
{
    /**
     * Inject QR code into signed invoice XML.
     *
     * For Phase 2, QR code requires:
     * - Tags 1-5: Basic info (seller, VAT, timestamp, totals)
     * - Tags 6-9: Cryptographic data (hash, signature, public key, cert signature)
     */
    private function injectQrCode(string $signedXml, InvoiceXmlData $invoiceData, string $certificate): string
    {
        // Put the QR element in place before hashing, empty for now.
        //
        // Inserting it is a DOM round trip, and re-serializing changes bytes
        // the hash covers — self-closing tags, entity escapes — so a hash taken
        // before it does not describe the document that gets sent. ZATCA
        // recomputes from what it receives and answered "invoice xml hash does
        // not match with qr code invoice xml hash" for every simplified
        // document. Hashing the settled shape, with the QR excluded as the
        // specification requires, is what makes tag 6 true of the document
        // carrying it.
        $signedXml = $this->qrInjector->inject($signedXml, '');

        $invoiceHash = $this->hasher->hash($signedXml);

        // Extract signature from signed XML
        $signature = $this->signer->extractSignature($signedXml);

        // Extract public key from certificate
        $certResource = openssl_x509_read($certificate);
        if ($certResource === false) {
            throw new RuntimeException('Failed to parse certificate');
        }
        $pubKeyResource = openssl_pkey_get_public($certResource);
        $pubKeyDetails = openssl_pkey_get_details($pubKeyResource);
        $publicKeyPem = $pubKeyDetails['key'] ?? '';

        // Extract raw public key bytes (without PEM headers)
        $publicKeyDer = base64_decode(str_replace(
            ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
            '',
            $publicKeyPem
        ));

        // CertificateService already walks the DER for the certificate's
        // signature. A second copy of the walk lived here and produced the
        // same 71 bytes, which is the good case: two implementations of one
        // rule agree until one of them is fixed.
        $certSignature = $this->certificateService->getCertificateSignature($certificate);

        // Build QR code data
        $qrData = new QrCodeData(
            sellerName: $invoiceData->sellerName,
            vatNumber: $invoiceData->sellerVatNumber,
            timestamp: $invoiceData->issueDate.'T'.$invoiceData->issueTime,
            invoiceTotal: number_format($invoiceData->total, 2, '.', ''),
            vatTotal: number_format($invoiceData->taxAmount, 2, '.', ''),
            // hash() already returns base64. Encoding it again put base64 of
            // base64 in tag 6, which is not the hash ZATCA recomputes.
            invoiceHash: $invoiceHash,
            signature: $signature ?? '',
            // Raw bytes, not base64. Tags 6 and 7 carry base64 text and these
            // two carry DER; encoding them made the QR a third longer than
            // ZATCA's, and the authority answered "ECDSA Public Key does not
            // match with qr code ECDSA public key".
            publicKey: $publicKeyDer,
            certificateSignature: $certSignature,
        );

        // Nine tags whenever the cryptographic material exists, which after
        // signing it does. This chose by document type and chose the wrong way
        // round: the stamp belongs on simplified invoices, which ZATCA verifies
        // from the QR because it never sees them before they are issued. A
        // five-tag QR on a simplified invoice is refused with "Failed to
        // validate QR code", and every one of ours was refused.
        try {
            $qrCode = $this->qrGenerator->generatePhase2($qrData);
        } catch (\Exception $e) {
            // Falling back silently produced a QR the authority rejects and
            // said nothing about why, so say it.
            $this->warn('Phase 2 QR unavailable ('.$e->getMessage().'); falling back to five tags.');
            $qrCode = $this->qrGenerator->generatePhase1($qrData);
        }

        // Inject QR code into the XML
        return $this->qrInjector->inject($signedXml, $qrCode);
    }
}
