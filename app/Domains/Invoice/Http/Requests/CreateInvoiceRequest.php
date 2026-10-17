<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Services\TenantResolver;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * An invoice draft from the API, checked by the rules of the organization's
 * country (docs/architecture/JURISDICTION-ROUTING.md).
 *
 * The organization is the one the caller acts for. Its country's rules
 * apply whether or not the country issues now, since a draft can still be
 * saved while it is switched off; with no organization, the default
 * country's do.
 *
 * Beside the country's fields, one every API invoice may carry: issued_at,
 * the moment a till made the sale, for a till issuing what it sold while it
 * was offline. Whether a country takes it, and within what bound, is its
 * issuing step's to say; the date it falls on is the invoice's issue date.
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
            // An instant, so it names its offset: a bare local time would be
            // read on the server's clock, which is not the till's.
            'issued_at' => ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'issued_at.regex' => __('The sale time (issued_at) must include its UTC offset, as in 2026-10-16T23:30:00+03:00.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $saleDate = $this->saleDate();

            if ($saleDate !== null && filled($this->input('issue_date'))
                && substr((string) $this->input('issue_date'), 0, 10) !== $saleDate) {
                $validator->errors()->add('issue_date', __('The issue date must be the date of the sale time (issued_at), on the organization\'s clock.'));
            }
        });
    }

    /**
     * A sale time with no issue date beside it names the date: the day it
     * fell on where the organization trades, which is the date its document
     * says it was issued.
     */
    protected function prepareForValidation(): void
    {
        $saleDate = $this->saleDate();

        if ($saleDate !== null && blank($this->input('issue_date'))) {
            $this->merge(['issue_date' => $saleDate]);
        }

        parent::prepareForValidation();
    }

    /**
     * The date of the sale time on the organization's clock, or null when
     * there is none or it cannot be read - validation says which.
     */
    private function saleDate(): ?string
    {
        $saleTime = $this->input('issued_at');

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
