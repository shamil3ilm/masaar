<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Universal compliance status resolver across jurisdictions.
 *
 * Provides a single polymorphic interface for external systems, ERPs, and mobile POS
 * to inspect authority filing, clearance, and transmission telemetry without
 * requiring country-specific branching on the caller's side.
 */
class InvoiceComplianceStatus
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Resolve the normalized compliance status for an invoice.
     *
     * @param  object  $invoice
     * @param  Organization  $organization
     * @return array<string, mixed>
     */
    public function statusFor(object $invoice, Organization $organization): array
    {
        $country = strtoupper((string) ($organization->country ?? 'SA'));
        $jurisdiction = $this->jurisdictions->forOrganization($organization);
        $system = $jurisdiction->filingSystem();

        $compliance = match ($country) {
            'SA' => $this->saudiStatus($invoice, $system),
            'AE' => $this->uaeStatus($invoice, $system),
            default => $this->genericStatus($invoice, $system),
        };

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'country' => $country,
            'status' => $invoice->status?->value ?? (string) $invoice->status,
            'compliance' => $compliance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saudiStatus(object $invoice, string $system): array
    {
        $sub = DB::table('invoice_submissions')
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->first();

        $zatcaResponse = is_string($invoice->zatca_response)
            ? json_decode($invoice->zatca_response, true)
            : $invoice->zatca_response;

        $status = $sub->clearance_status
            ?? $sub->reporting_status
            ?? $sub->state
            ?? ($zatcaResponse['clearance_status'] ?? $zatcaResponse['reporting_status'] ?? null)
            ?? 'NOT_SUBMITTED';

        $warnings = ! empty($sub->zatca_warnings)
            ? (is_string($sub->zatca_warnings) ? json_decode($sub->zatca_warnings, true) : $sub->zatca_warnings)
            : ($zatcaResponse['warnings'] ?? []);

        $errors = ! empty($sub->zatca_errors)
            ? (is_string($sub->zatca_errors) ? json_decode($sub->zatca_errors, true) : $sub->zatca_errors)
            : ($zatcaResponse['errors'] ?? []);

        return [
            'system' => $system,
            'filing_status' => $status,
            'icv' => $invoice->icv,
            'hash' => $invoice->hash,
            'submission_id' => $sub?->id,
            'submitted_at' => $sub?->created_at,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uaeStatus(object $invoice, string $system): array
    {
        $doc = DB::table('peppol_documents')
            ->where('invoice_id', $invoice->id)
            ->first();

        $errors = ! empty($doc->errors)
            ? (is_string($doc->errors) ? json_decode($doc->errors, true) : $doc->errors)
            : [];

        return [
            'system' => $system,
            'transmission_status' => $doc->status ?? 'NOT_QUEUED',
            'reporting_status' => $doc->reporting_status ?? 'NOT_REPORTED',
            'document_id' => $doc?->id,
            'asp_message_id' => $doc?->asp_message_id,
            'mls_code' => $doc?->mls_code,
            'authority_reference' => $doc?->authority_reference,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function genericStatus(object $invoice, string $system): array
    {
        return [
            'system' => $system,
            'status' => $invoice->status?->value ?? (string) $invoice->status,
        ];
    }
}
