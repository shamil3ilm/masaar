<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\FilingPausedMail;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A device ZATCA refused the credentials of stops filing until they are
 * renewed.
 *
 * "401 Unauthorized - Check authentication certificate and secret and
 * resubmit" (E-Invoicing Detailed Guidelines, overview of responses and
 * required actions). Sending more from the device only earns the same
 * answer, so it is paused: nothing more is sent from it, what it issues
 * meanwhile waits in the offline queue, and its organization's
 * administrators are told once. Renewing or onboarding it again resumes it,
 * and what waited is filed (FilingResume).
 */
class FilingPause
{
    public function __construct(
        private readonly Tills $tills,
        private readonly AuditService $audit,
        private readonly ThemeService $themes,
    ) {}

    /**
     * Pause the device that filed this invoice, when the answer was ZATCA
     * refusing its credentials.
     */
    public function afterAnswer(Invoice $invoice, FatooraResponse $response): void
    {
        if ($response->errorCode !== ErrorCode::ZATCA_UNAUTHORIZED) {
            return;
        }

        $organizationId = (string) $invoice->org_id;
        $unit = $this->tills->find($organizationId, (string) ($invoice->device_id ?? $organizationId));

        if ($unit === null || ! $this->tills->pauseFiling($unit, (string) ($response->errorMessages[0] ?? 'Unauthorized'))) {
            return;
        }

        $organization = Organization::query()->find($organizationId);
        Log::critical('ZATCA refused a device\'s credentials; its filing is paused until they are renewed', [
            'org_id' => $organizationId,
            'device_id' => $unit->id,
        ]);

        if ($organization === null) {
            return;
        }

        $this->audit->logFor($organizationId, 'zatca.filing.paused', $organization, ['device_id' => $unit->id]);

        // Once the answer is committed, never inside it: this runs within
        // the transaction that records ZATCA's answer, and a mail server
        // that failed there rolled the answer back and left the submission
        // "submitted". A mail that fails is logged; the filing stands.
        $brand = $this->themes->forScope($organizationId);
        $label = $this->tills->label($unit);

        DB::afterCommit(function () use ($organization, $label, $brand): void {
            // One mail each, in its reader's language.
            foreach ($organization->users()->wherePivot('role', 'admin')->wherePivot('status', 'active')->get() as $admin) {
                try {
                    Mail::to($admin)->send(new FilingPausedMail((string) $organization->name, $label, $brand));
                } catch (Throwable $e) {
                    Log::error('The filing-paused mail could not be sent; the pause stands', [
                        'org_id' => $organization->id,
                        'user_id' => $admin->id,
                        'exception' => $e,
                    ]);
                }
            }
        });
    }

    /** Whether the device that signed this invoice is paused. */
    public function paused(Invoice $invoice): bool
    {
        $organizationId = (string) $invoice->org_id;

        return $this->tills->find($organizationId, (string) ($invoice->device_id ?? $organizationId))?->filing_paused_at !== null;
    }

    /**
     * @throws FatooraException while the device is paused, which the offline
     *                          fallback answers by queueing the document
     */
    public function assertFiling(Invoice $invoice): void
    {
        if ($this->paused($invoice)) {
            throw new FatooraException(
                __('ZATCA refused this device\'s certificate, so filing from it is paused. Renew the certificate; what it issues meanwhile is filed then.'),
                ErrorCode::ZATCA_UNAUTHORIZED,
                ['invoice_id' => $invoice->id, 'device_id' => $invoice->device_id],
            );
        }
    }
}
