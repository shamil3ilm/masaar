<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\DeviceCredentials;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;

/**
 * Puts a submission's signed document in front of ZATCA: the one place that
 * chooses clearance or reporting.
 *
 * The choice is the submission's submission_type, decided once when the row
 * is opened (InvoiceSubmission::typeFor). It was made twice more: the
 * synchronous filing and the offline drain asked the invoice whether it was
 * B2B, and the queue job asked the row, so two paths could disagree about the
 * same document.
 *
 * It sends only. Every caller resolves the device and moves the row through
 * SubmissionLedger before calling, and records the answer through it after.
 */
final class Filer
{
    public function __construct(private readonly FatooraClient $client) {}

    public function send(InvoiceSubmission $submission, string $signedXml, string $hash, string $uuid, DeviceCredentials $device): FatooraResponse
    {
        return $submission->isClearance()
            ? $this->client->clearInvoice($signedXml, $hash, $uuid, $device)
            : $this->client->reportInvoice($signedXml, $hash, $uuid, $device);
    }
}
