<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLink;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Links that open one issued invoice without signing in, for a QR code on
 * the invoice or a sign by the till.
 *
 * The token is 256 random bits, so a link is found only by having it: it is
 * looked up by its SHA-256, which is all an index holds, and names its
 * organization and invoice itself, so no link opens another tenant's
 * invoice. Revoking one closes it for good; the invoice can be given a new
 * one. Opening is rate limited where it is routed.
 *
 * A link may be given a lifetime, after which it opens nothing either. The
 * organization chooses the default (invoice_link_days); none is never,
 * which is what every link was before lifetimes.
 */
class InvoiceLinks
{
    /** The lifetimes, in days, a link or an organization's default may have. */
    public const LIFETIMES = [7, 30, 90, 365];

    /**
     * The invoice's open link, or a new one: printing the invoice again does
     * not mint a link per copy.
     */
    public function forInvoice(Invoice $invoice, ?string $userId = null): InvoiceLink
    {
        return $this->active((string) $invoice->org_id, (string) $invoice->id)->first()
            ?? $this->create($invoice, $userId, $this->defaultDays((string) $invoice->org_id));
    }

    /**
     * A new link, open for $days days or, given null, until it is revoked.
     */
    public function create(Invoice $invoice, ?string $userId = null, ?int $days = null): InvoiceLink
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return InvoiceLink::acrossTenants()->create([
            'org_id' => $invoice->org_id,
            'invoice_id' => $invoice->id,
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'created_by' => $userId,
            'expires_at' => $days === null ? null : now()->addDays($days),
        ]);
    }

    /** How long the organization's new links last, in days; null for never. */
    public function defaultDays(string $organizationId): ?int
    {
        $days = Organization::query()->whereKey($organizationId)->value('invoice_link_days');

        return $days === null ? null : (int) $days;
    }

    public function setDefaultDays(Organization $organization, ?int $days): void
    {
        $organization->forceFill(['invoice_link_days' => $days])->save();
    }

    /**
     * The organization's open links, newest first, with their invoices'
     * numbers; one invoice's when it is named.
     *
     * @return Collection<int, InvoiceLink>
     */
    public function active(string $organizationId, ?string $invoiceId = null): Collection
    {
        return InvoiceLink::acrossTenants()
            ->with(['invoice' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'org_id', 'invoice_number'])])
            ->where('org_id', $organizationId)
            ->when($invoiceId !== null, fn ($query) => $query->where('invoice_id', $invoiceId))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();
    }

    public function find(string $organizationId, string $id): ?InvoiceLink
    {
        return InvoiceLink::acrossTenants()->where('org_id', $organizationId)->whereKey($id)->first();
    }

    public function revoke(string $organizationId, string $id): void
    {
        $this->find($organizationId, $id)?->forceFill(['revoked_at' => now()])->save();
    }

    public function url(InvoiceLink $link): string
    {
        return route('invoice-link.show', $link->token);
    }

    /**
     * The link a token belongs to, counted as opened; null for one that
     * never existed or was revoked - the two look the same. An expired one
     * is returned uncounted, for the caller to say it has expired.
     */
    public function open(string $token): ?InvoiceLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        $link = InvoiceLink::acrossTenants()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($link !== null && ! $link->expired()) {
            $link->forceFill(['last_opened_at' => now(), 'open_count' => $link->open_count + 1])->saveQuietly();
        }

        return $link;
    }

    /** The organization's invoice with this number, lines and all. */
    public function invoiceNumbered(string $organizationId, string $number): ?Invoice
    {
        return Invoice::acrossTenants()
            ->with('lines')
            ->where('org_id', $organizationId)
            ->where('invoice_number', $number)
            ->first();
    }

    public function organization(InvoiceLink $link): ?Organization
    {
        return Organization::query()->find($link->org_id);
    }

    /** The link's invoice, in the link's own organization only. */
    public function invoice(InvoiceLink $link): ?Invoice
    {
        return Invoice::acrossTenants()
            ->with('lines')
            ->where('org_id', $link->org_id)
            ->whereKey($link->invoice_id)
            ->first();
    }
}
