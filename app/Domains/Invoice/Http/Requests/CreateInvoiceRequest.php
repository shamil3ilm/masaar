<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Services\TenantResolver;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * An invoice draft from the API, checked by the rules of the organization's
 * country (docs/architecture/JURISDICTION-ROUTING.md).
 *
 * The organization is the one the caller acts for. Its country's rules
 * apply whether or not the country issues now, since a draft can still be
 * saved while it is switched off; with no organization, the default
 * country's do.
 *
 * Beside the country's fields, two every API invoice may carry:
 *
 *   branch_id  the branch it comes from, one of the organization's own: its
 *              address is the seller's
 *   till_id    the till that made the sale - one of the organization's EGS
 *              units - whose counter, chain and certificate it is issued on;
 *              or till_number, the till's number within branch_id. Naming
 *              neither issues it on the branch's first till, else head
 *              office's
 *   sold_at    the moment a till made the sale, for a till issuing what it
 *              sold while it was offline. It is the actual supply: its date
 *              is the supply date when none is given, and the daily close
 *              and the sales reports count the sale on it. It is not the
 *              moment of issue - that is when the platform generates the
 *              final invoice (ZATCA Detailed Guidelines for E-Invoicing,
 *              p60). issued_at, the name it was first sent under, is still
 *              taken and means the same.
 */
class CreateInvoiceRequest extends CountryInvoiceRequest
{
    /** The organization's country's module. */
    protected function jurisdiction(): Jurisdiction
    {
        return app(JurisdictionResolver::class)->forRules(app(TenantResolver::class)->getOrganization());
    }

    protected function resolveInvoiceRules(): InvoiceRules
    {
        return $this->jurisdiction()->invoiceRules();
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Looked up within the caller's organization, so a branch of
            // anyone else's is not found: its certificate is not theirs to
            // sign with.
            'branch_id' => ['nullable', 'string', 'uuid', Rule::exists('branches', 'id')
                ->where('org_id', app(TenantResolver::class)->getOrganizationId())->withoutTrashed()],
            // Checked against the organization's tills when the draft is
            // made (Tills::named): its own, of the branch named, issuing.
            'till_id' => ['nullable', 'string', 'uuid'],
            'till_number' => ['nullable', 'integer', 'min:1'],
            // An instant, so it names its offset: a bare local time would be
            // read on the server's clock, which is not the till's.
            'sold_at' => ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
            'issued_at' => ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'sold_at.regex' => __('The sale time (sold_at) must include its UTC offset, as in 2026-10-16T23:30:00+03:00.'),
            'issued_at.regex' => __('The sale time (issued_at) must include its UTC offset, as in 2026-10-16T23:30:00+03:00.'),
        ];
    }

    /**
     * A sale time names the supply date when the invoice gives none: the day
     * the sale fell on where the organization trades. With no issue date the
     * invoice is dated today; issuing dates it again, to the day it is
     * generated.
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('sold_at')) && filled($this->input('issued_at'))) {
            $this->merge(['sold_at' => $this->input('issued_at')]);
        }

        $saleDate = $this->saleDate();

        if ($saleDate !== null && blank($this->input('supply_date'))) {
            $this->merge(['supply_date' => $saleDate]);
        }

        if ($saleDate !== null && blank($this->input('issue_date'))) {
            $this->merge(['issue_date' => CountryClock::now(app(TenantResolver::class)->getOrganization()?->country)->toDateString()]);
        }

        parent::prepareForValidation();
    }

    /**
     * The date of the sale time on the organization's clock, or null when
     * there is none or it cannot be read - validation says which.
     */
    private function saleDate(): ?string
    {
        $saleTime = $this->input('sold_at');

        if (! is_string($saleTime) || $saleTime === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($saleTime)
                ->setTimezone(CountryClock::timeZone(app(TenantResolver::class)->getOrganization()?->country))
                ->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
