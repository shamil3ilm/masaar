<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use Illuminate\Validation\Rule;

/**
 * The portal's UAE invoice form, validated as the API's request is.
 *
 * The rules, messages and the mapping to a draft are CreateUaeInvoiceRequest's;
 * only the form's shapes are turned into the API's first:
 *
 *   flags[]                the transaction-type boxes, each a position in the
 *                          eight-character code (BTAE-02)
 *   buyer_endpoint_choice  the buyer's own endpoint, or one of the three the
 *                          guidelines reserve. The deemed-supply and export
 *                          endpoints set their scenario's flag too, so the
 *                          endpoint and the code cannot say different things.
 *   buyer_legal_registration, delivery_address
 *                          optional groups: left empty they are absent, not
 *                          a group of blanks the rules would require.
 *
 * Open only to a UAE organization on a deployment that offers it; anyone
 * else gets the 404 the API's UaeInvoicing gives, before any validation.
 */
class PortalUaeInvoiceRequest extends CreateUaeInvoiceRequest
{
    /** The reserved endpoints, by the form's name for them. */
    public const RESERVED_ENDPOINTS = [
        'deemed' => PintAe::DEEMED_SUPPLY_BUYER,
        'not_yet' => PintAe::BUYER_NOT_YET_ON_EINVOICING,
        'export' => PintAe::EXPORT_BUYER,
    ];

    public function authorize(): bool
    {
        app(UaePortal::class)->organization();

        return true;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'buyer_endpoint_choice' => ['nullable', Rule::in(['own', ...array_keys(self::RESERVED_ENDPOINTS)])],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['integer', 'between:0,7'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $choice = $this->input('buyer_endpoint_choice');
        $flags = $this->flags();

        if ($choice === 'deemed') {
            $flags[PintAe::FLAG_DEEMED_SUPPLY] = '1';
        } elseif ($choice === 'export') {
            $flags[PintAe::FLAG_EXPORT] = '1';
        }

        $this->merge([
            'transaction_flags' => implode('', $flags),
            'buyer_endpoint' => is_string($choice) && isset(self::RESERVED_ENDPOINTS[$choice])
                ? PintAe::SCHEME.':'.self::RESERVED_ENDPOINTS[$choice]
                : $this->input('buyer_endpoint'),
            // A blank country is the UAE, as the API takes an absent one.
            'buyer_address' => is_array($this->input('buyer_address'))
                ? array_filter($this->input('buyer_address'), fn (mixed $value): bool => $value !== null)
                : $this->input('buyer_address'),
            'buyer_legal_registration' => self::given($this->input('buyer_legal_registration')),
            'delivery_address' => self::given($this->input('delivery_address')),
        ]);

        parent::prepareForValidation();
    }

    /**
     * The ticked boxes as the eight characters of the code.
     *
     * @return list<string>
     */
    private function flags(): array
    {
        $flags = str_split(PintAe::NO_FLAGS);

        foreach ((array) $this->input('flags', []) as $position) {
            $whole = is_int($position) || (is_string($position) && ctype_digit($position));

            if ($whole && isset($flags[(int) $position])) {
                $flags[(int) $position] = '1';
            }
        }

        return $flags;
    }

    /** An optional group of fields, or null when none of them was filled in. */
    private static function given(mixed $group): mixed
    {
        if (! is_array($group)) {
            return $group;
        }

        return array_filter($group, fn (mixed $value): bool => $value !== null && $value !== '') === [] ? null : $group;
    }
}
