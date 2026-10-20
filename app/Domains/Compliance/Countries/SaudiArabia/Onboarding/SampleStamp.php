<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\InvoiceHasher;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeGenerator;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeInjector;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\XadesSigner;
use Closure;
use RuntimeException;

/**
 * The stamp and QR a compliance sample carries when it is submitted.
 *
 * ZATCA's compliance check judges the six samples as it will judge the
 * device's real documents: each signed with the device's own certificate
 * and, on a simplified one, a QR carrying that stamp. BR-KSA-27 wants the QR
 * (KSA-14) on every document, BR-KSA-28 to 30 the stamp's structure, and
 * BR-KSA-60 the cryptographic stamp on simplified invoices and their notes
 * (XML Implementation Standard v1.2; KSA schematron). The portal and API
 * onboarding sent the samples with an empty signature placeholder and no QR,
 * so the three simplified ones could not pass; only fatoora:onboard signed
 * them. This is that command's stamp, shared, so there is one.
 *
 * The device's compliance CSID signs: during onboarding it is the only
 * certificate the device holds, and the one /compliance/invoices
 * authenticates with.
 */
final class SampleStamp
{
    public function __construct(
        private readonly InvoiceHasher $hasher,
        private readonly XadesSigner $signer,
        private readonly QrCodeGenerator $qrGenerator,
        private readonly QrCodeInjector $qrInjector,
        private readonly CertificateService $certificateService,
    ) {}

    /**
     * The finalize step ComplianceSampleSet::build() takes, signing with this
     * key under this certificate. The certificate may be stored as ZATCA's
     * binarySecurityToken or as a PEM.
     *
     * @return Closure(string, InvoiceXmlData): string
     */
    public function with(string $privateKey, string $certificate): Closure
    {
        $pem = CertificatePem::from($certificate)
            ?? throw new RuntimeException('The compliance certificate is missing, so the samples cannot be signed.');

        return fn (string $xml, InvoiceXmlData $data): string => $this->stamp(
            $this->signer->sign($xml, $privateKey, $pem),
            $data,
            $pem,
        );
    }

    /**
     * Put the QR into a signed sample.
     *
     * The QR element goes in empty first and the hash is taken of that
     * settled shape. Inserting it is a DOM round trip, and a hash taken before
     * it described bytes that were never sent: ZATCA answered "invoice xml
     * hash does not match with qr code invoice xml hash" for every simplified
     * sample. The hash excludes the QR, as the specification requires, so tag
     * 6 is true of the document carrying it.
     */
    private function stamp(string $signedXml, InvoiceXmlData $data, string $certificate): string
    {
        $signedXml = $this->qrInjector->inject($signedXml, '');

        $publicKey = openssl_pkey_get_details(
            openssl_pkey_get_public($certificate) ?: throw new RuntimeException('Failed to parse certificate')
        )['key'] ?? '';

        $qrData = new QrCodeData(
            sellerName: $data->sellerName,
            vatNumber: $data->sellerVatNumber,
            timestamp: $data->issueDate.'T'.$data->issueTime,
            invoiceTotal: number_format($data->total, 2, '.', ''),
            vatTotal: number_format($data->taxAmount, 2, '.', ''),
            // Tags 6 and 7 carry base64 text, tags 8 and 9 raw DER: encoding
            // the key made the QR a third longer than ZATCA's, and the
            // authority answered "ECDSA Public Key does not match with qr code
            // ECDSA public key".
            invoiceHash: $this->hasher->hash($signedXml),
            signature: $this->signer->extractSignature($signedXml) ?? '',
            publicKey: base64_decode(str_replace(
                ['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n", "\r"],
                '',
                $publicKey
            )),
            certificateSignature: $this->certificateService->getCertificateSignature($certificate),
        );

        // All nine tags, never the five-tag fallback: a simplified sample
        // with a five-tag QR is refused with "Failed to validate QR code", so
        // falling back only moves the failure to the authority.
        return $this->qrInjector->inject($signedXml, $this->qrGenerator->generatePhase2($qrData));
    }
}
