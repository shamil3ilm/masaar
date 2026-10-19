<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeGenerator;
use App\Domains\Compliance\Engine\Contracts\Website as WebsitePort;
use App\Domains\Compliance\Engine\DTOs\SampleInvoice;
use App\Domains\Compliance\Engine\DTOs\SampleQr;
use BaconQrCode\Encoder\Encoder;

/**
 * Saudi Arabia on the public website.
 *
 * The sample's QR code is the five tags of a Phase 1 QR code (ZATCA's
 * E-Invoicing Security Features Implementation Standards, QR code: seller
 * name, VAT registration number, timestamp, total with VAT, VAT total), in
 * the TLV encoding and by the same generator an issued invoice's is. It
 * carries no hash, signature or key: nothing about it is signed, so it
 * proves nothing about any invoice.
 */
class Website implements WebsitePort
{
    /**
     * Fifteen digits, starting and ending in 3, as a VAT registration number
     * is (BR-KSA-40), and none issued: the run of zeros marks it a sample.
     */
    public const SAMPLE_VAT_NUMBER = '300000000000003';

    public function __construct(private readonly QrCodeGenerator $qr) {}

    public function sampleQr(SampleInvoice $sample): SampleQr
    {
        $payload = $this->qr->generatePhase1(new QrCodeData(
            sellerName: $sample->sellerName,
            vatNumber: self::SAMPLE_VAT_NUMBER,
            timestamp: $sample->issuedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            invoiceTotal: $sample->total,
            vatTotal: $sample->tax,
        ));

        // Base64, read as Latin-1, as an issued invoice's payload is drawn.
        return new SampleQr($payload, Encoder::DEFAULT_BYTE_MODE_ENCODING, self::SAMPLE_VAT_NUMBER);
    }
}
