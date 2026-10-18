<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\BusinessQrCode;
use App\Domains\Invoice\Models\Invoice;
use Closure;
use Illuminate\Support\Collection;

/**
 * An organization's own QR codes, and what each one says when scanned.
 *
 * Three kinds: a contact card (vCard 3.0, which every phone's camera offers
 * to save as a contact), payment details as plain text (bank, IBAN, amount,
 * reference - read by a person, not a payment scheme), and a link, to the
 * organization's location on a map or to its website. The invoice link, the
 * fourth kind the QR page offers, is InvoiceLinks'.
 *
 * Read as UTF-8: a name or an address may be in Arabic.
 */
class BusinessQrCodes
{
    public const ENCODING = 'UTF-8';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(string $kind): array
    {
        return match ($kind) {
            BusinessQrCode::CONTACT => [
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9 ()-]{5,40}$/'],
                'email' => ['nullable', 'email', 'max:120'],
                'website' => ['nullable', 'url:http,https', 'max:200'],
                'address' => ['nullable', 'string', 'max:200'],
            ],
            BusinessQrCode::PAYMENT => [
                'bank' => ['required', 'string', 'max:80'],
                'iban' => ['required', 'string', 'max:42', $this->ibanRule()],
                'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'currency' => ['nullable', 'string', 'size:3', 'alpha'],
                'reference' => ['nullable', 'string', 'max:60'],
            ],
            default => [
                'url' => ['required', 'url:http,https', 'max:500'],
            ],
        } + ['label' => ['nullable', 'string', 'max:80']];
    }

    /**
     * @param  array<string, mixed>  $data  validated against rules($kind)
     */
    public function create(string $organizationId, string $kind, array $data): BusinessQrCode
    {
        $fields = array_map(
            fn ($value): ?string => $value === null || $value === '' ? null : trim((string) $value),
            array_diff_key($data, ['label' => true]),
        );

        if (isset($fields['iban'])) {
            $fields['iban'] = strtoupper((string) preg_replace('/\s+/', '', $fields['iban']));
        }

        return BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::query()->create([
            'org_id' => $organizationId,
            'kind' => $kind,
            'label' => trim((string) ($data['label'] ?? '')) ?: $this->defaultLabel($kind),
            'data' => $fields,
        ]));
    }

    /**
     * @return Collection<int, BusinessQrCode>
     */
    public function all(string $organizationId): Collection
    {
        return BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::query()->where('org_id', $organizationId)->orderBy('created_at')->get());
    }

    public function find(string $organizationId, string $id): ?BusinessQrCode
    {
        return BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::query()->where('org_id', $organizationId)->whereKey($id)->first());
    }

    /**
     * Removed, not erased: it can be brought back (restore), and who removed
     * it is kept.
     */
    public function delete(string $organizationId, string $id): void
    {
        $this->find($organizationId, $id)?->delete();
    }

    /**
     * The codes removed, the latest first.
     *
     * @return Collection<int, BusinessQrCode>
     */
    public function removed(string $organizationId): Collection
    {
        return BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::onlyTrashed()->where('org_id', $organizationId)->latest('deleted_at')->get());
    }

    public function restore(string $organizationId, string $id): ?BusinessQrCode
    {
        $code = BusinessQrCode::withoutTenantScope(fn () => BusinessQrCode::onlyTrashed()->where('org_id', $organizationId)->whereKey($id)->first());
        $code?->restore();

        return $code;
    }

    /**
     * What the code says when scanned. Payment details printed on an
     * invoice take its amount due and number where the code leaves them out.
     */
    public function payload(BusinessQrCode $code, ?Invoice $invoice = null): string
    {
        $data = $code->data;

        return match ($code->kind) {
            BusinessQrCode::CONTACT => $this->vCard($data),
            BusinessQrCode::PAYMENT => $this->payment($data, $invoice),
            default => (string) ($data['url'] ?? ''),
        };
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function vCard(array $data): string
    {
        $lines = array_filter([
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.$this->escape($data['name'] ?? ''),
            'ORG:'.$this->escape($data['name'] ?? ''),
            ($data['phone'] ?? null) ? 'TEL;TYPE=WORK,VOICE:'.$this->escape($data['phone']) : null,
            ($data['email'] ?? null) ? 'EMAIL;TYPE=INTERNET:'.$this->escape($data['email']) : null,
            ($data['website'] ?? null) ? 'URL:'.$this->escape($data['website']) : null,
            // The whole address as the street: the card does not need it
            // split into the parts a postal system would.
            ($data['address'] ?? null) ? 'ADR;TYPE=WORK:;;'.$this->escape($data['address']).';;;;' : null,
            'END:VCARD',
        ]);

        return implode("\r\n", $lines);
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function payment(array $data, ?Invoice $invoice): string
    {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        if ($amount === null && $invoice !== null) {
            $amount = number_format((float) $invoice->total, 2, '.', '');
            $currency = (string) $invoice->currency;
        }

        $reference = $data['reference'] ?? ($invoice?->invoice_number);

        return implode("\n", array_filter([
            'Bank / البنك: '.($data['bank'] ?? ''),
            'IBAN / الآيبان: '.($data['iban'] ?? ''),
            $amount !== null ? 'Amount / المبلغ: '.number_format((float) $amount, 2, '.', '').($currency ? ' '.strtoupper($currency) : '') : null,
            $reference ? 'Reference / المرجع: '.$reference : null,
        ]));
    }

    /** RFC 6350's escaping of a text value. */
    private function escape(?string $value): string
    {
        return str_replace(['\\', "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\\;'], trim((string) $value));
    }

    private function defaultLabel(string $kind): string
    {
        return match ($kind) {
            BusinessQrCode::CONTACT => __('Save our contact details'),
            BusinessQrCode::PAYMENT => __('Pay by bank transfer'),
            default => __('Visit us'),
        };
    }

    /**
     * An IBAN by ISO 13616: a country prefix, check digits and an account
     * number, whose mod-97 check (ISO 7064) is 1.
     */
    private function ibanRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $value));

            if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
                $fail(__('That is not an IBAN.'));

                return;
            }

            $digits = '';

            foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
                $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            }

            $remainder = 0;

            foreach (str_split($digits, 7) as $chunk) {
                $remainder = (int) ($remainder.$chunk) % 97;
            }

            if ($remainder !== 1) {
                $fail(__('That IBAN\'s check digits are wrong. Check it against your bank statement.'));
            }
        };
    }
}
