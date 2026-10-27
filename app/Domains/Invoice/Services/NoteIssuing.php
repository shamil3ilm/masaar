<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\OrganizationAction;
use App\Domains\Platform\Approvals\OrganizationApprovals;
use App\Domains\Platform\Approvals\Preview;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Issuing a credit or debit note a person asked for, when the organization
 * wants a second person to approve it (H6: an unchecked note takes output
 * VAT off the return; IR Art 40(1) allows notes only for real events).
 *
 * The policy is the organization's, layered as every customization is: the
 * platform's default (off) and the organization's own, with an optional
 * threshold - only notes of at least that total wait. There is no per-person
 * level: a control a person could turn off for themselves controls nothing.
 *
 * Held here are notes a person issues from the portal, and notes an
 * integration sends through the API or the pipeline (holdIntegration): those
 * wait as a request no person made, which any approver decides, and the
 * approver issues the note. Notes the platform
 * issues itself - a refund from billing, the credit note an insurer's
 * decision makes - do not pass through here: they come from finance's own
 * approved records (a refund under its hold, a settled split) and are
 * already decided there.
 *
 * Approved, the note is issued exactly as the portal issues it, as the
 * person who asked - who must still be allowed to issue it at its branch -
 * with every check before the counter run again: the platform's switches,
 * the country's rules, the original's acceptance. What was approved is what
 * is issued: a draft changed since is refused, to be asked for again.
 */
final class NoteIssuing implements OrganizationAction
{
    public const ACTION = 'notes.issue';

    public const POLICY = 'approvals.notes';

    public const THRESHOLD = 'approvals.notes_threshold';

    public function __construct(
        private readonly InvoiceFinder $invoices,
        private readonly ComplianceEngine $engine,
        private readonly Access $access,
        private readonly Customizations $customizations,
    ) {}

    /** Whether this draft waits for a second person before it is issued. */
    public function holds(Invoice $invoice, Organization $organization): bool
    {
        if (! $this->isNote($invoice) || ! $this->customizations->get(self::POLICY, Scope::organization((string) $organization->id))) {
            return false;
        }

        $threshold = $this->customizations->get(self::THRESHOLD, Scope::organization((string) $organization->id));

        return $threshold === null || $threshold === '' || bccomp((string) $invoice->total, (string) $threshold, 2) >= 0;
    }

    /**
     * Hold a note an integration sent, when the policy holds it: the request
     * it waits on - the open one, on a retry - or null to issue it now (not
     * held, or nobody could ever approve it, which is recorded as bypassed).
     *
     * @return array{id: string, status: string, expires_at: ?string}|null
     */
    public function holdIntegration(Invoice $invoice, Organization $organization, ?User $person = null): ?array
    {
        if (! $this->holds($invoice, $organization)) {
            return null;
        }

        $via = $invoice->created_via instanceof \BackedEnum ? $invoice->created_via->value : $invoice->created_via;

        $request = app(OrganizationApprovals::class)->submitFromIntegration($organization, self::ACTION, [
            ...$this->payload($invoice),
            'via' => $via === null ? null : (string) $via,
            'license_id' => $invoice->license_id === null ? null : (string) $invoice->license_id,
        ], (string) $invoice->adjustment_reason, $person);

        return $request === null ? null : [
            'id' => (string) $request->id,
            'status' => (string) $request->status,
            'expires_at' => $request->expires_at?->toIso8601String(),
        ];
    }

    /**
     * What a request carries: the note, and what it says, so a draft
     * changed after it was asked for is not the one issued.
     *
     * @return array{invoice_id: string, fingerprint: string}
     */
    public function payload(Invoice $invoice): array
    {
        return ['invoice_id' => (string) $invoice->id, 'fingerprint' => $this->fingerprint($invoice)];
    }

    public function label(): string
    {
        return __('Issue a credit or debit note');
    }

    public function approverRoles(): array
    {
        return MemberRole::ADMINISTERING;
    }

    public function preview(array $payload, Organization $organization, ?User $requester): Preview
    {
        $note = $this->draft($organization, (string) ($payload['invoice_id'] ?? ''));

        return new Preview(
            __(':type :number for :total :currency, correcting :original', [
                'type' => $this->typeLabel($note),
                'number' => $note->invoice_number,
                'total' => $note->total,
                'currency' => $note->currency,
                'original' => $note->billing_ref,
            ]),
            'Invoice',
            (string) $note->id,
            [],
            ['total' => (string) $note->total, 'tax_amount' => (string) $note->tax_amount, 'reason' => $note->adjustment_reason],
        );
    }

    public function execute(array $payload, Organization $organization, User $requester): ActionResult
    {
        $note = $this->draft($organization, (string) ($payload['invoice_id'] ?? ''));

        if (! hash_equals((string) ($payload['fingerprint'] ?? ''), $this->fingerprint($note))) {
            throw new DomainException(__('The note was changed after it was sent for approval, so it was not issued. Send it for approval again.'));
        }

        if (Gate::forUser($requester)->denies('issue', $note)) {
            throw new DomainException(__('The person who asked can no longer issue at this branch.'));
        }

        // As the portal asks before issuing: the platform's switches, the
        // country, the plan, the company's review, the person's own switch.
        $decision = $this->access->decide($organization, $requester, Feature::IssueInvoices);

        if (! $decision->allowed) {
            throw new DomainException(__('The note was not issued: :reason', ['reason' => $decision->reason]));
        }

        // Issued by the person who asked; the approver is on the request.
        if ($note->issued_by === null) {
            $note->forceFill(['issued_by' => $requester->id])->save();
        }

        $jurisdiction = $this->engine->jurisdictionFor($organization);
        $outcome = $this->engine->issue($note, $organization, new IssueOptions(
            file: $jurisdiction->transmission()->readyToFile($organization),
        ));

        if (! $outcome->issued) {
            throw new DomainException($outcome->errors[0] ?? __('The note could not be issued.'));
        }

        return new ActionResult(match (true) {
            $outcome->errors !== [] => __('Issued, and filing did not succeed: :error The invoice keeps its counter; its page says what happens next.', ['error' => $outcome->errors[0]]),
            ! $outcome->attemptedFiling => $jurisdiction->notices()->notFiled(),
            default => __('Issued.'),
        }, ['invoice_id' => (string) $note->id]);
    }

    private function draft(Organization $organization, string $invoiceId): Invoice
    {
        try {
            $note = $this->invoices->findWithLines((string) $organization->id, $invoiceId);
        } catch (ModelNotFoundException) {
            throw new DomainException(__('The note is no longer there.'));
        }

        if ($note->status !== InvoiceStatus::Draft || ! $this->isNote($note)) {
            throw new DomainException(__('Only a draft credit or debit note waits for approval; this one is no longer a draft.'));
        }

        return $note;
    }

    private function isNote(Invoice $invoice): bool
    {
        return in_array($invoice->document_type, [DocumentType::CreditNote, DocumentType::DebitNote], true);
    }

    private function typeLabel(Invoice $note): string
    {
        return $note->document_type === DocumentType::DebitNote ? __('Debit note') : __('Credit note');
    }

    /** What the note says: its amounts, its reason, its reference, its lines. */
    private function fingerprint(Invoice $note): string
    {
        $lines = $note->lines->sortBy('id')->map(fn ($line): array => [
            (string) $line->description, (string) $line->quantity, (string) $line->unit_price,
            (string) $line->tax_rate, (string) $line->tax_amount, (string) $line->line_total,
        ])->values()->all();

        return hash('sha256', json_encode([
            $note->document_type instanceof DocumentType ? $note->document_type->value : (string) $note->document_type,
            (string) $note->billing_ref, (string) $note->adjustment_reason, (string) $note->currency,
            (string) $note->subtotal, (string) $note->tax_amount, (string) $note->total,
            (string) $note->buyer_name, (string) $note->buyer_vat_number, $lines,
        ], JSON_THROW_ON_ERROR));
    }
}
