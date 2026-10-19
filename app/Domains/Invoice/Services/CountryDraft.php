<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A document the platform composes itself - a share of a split sale, the
 * credit note an insurer's decision calls for - checked by the country's
 * rules exactly as a request for it would be (CountryInvoiceRequest): its
 * defaults, its rules, its checks across fields, and then its draft.
 *
 * So a share is refused before anything is written for the same reasons an
 * invoice typed by hand would be, and never reaches the authority to be
 * refused there.
 */
final class CountryDraft
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string  $prefix  what the refusal's fields are named under, as "insurer"
     * @return array{draft: array<string, mixed>, countryFields: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public static function check(InvoiceRules $rules, array $data, string $prefix): array
    {
        // Nothing is left blank: a blank is no value, as a request arriving
        // over HTTP has it once its empty strings are null.
        $request = Request::create('/', 'POST', self::withoutBlanks($data));
        $request->merge($rules->defaults($request));

        $validator = Validator::make($request->all(), $rules->rules($request), $rules->messages());
        $validator->after(fn ($validator) => $rules->after($validator, $request));

        if ($validator->fails()) {
            $errors = [];

            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$prefix.'.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();

        return ['draft' => $rules->draft($validated), 'countryFields' => $rules->countryFields($validated)];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function withoutBlanks(array $data): array
    {
        $kept = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $kept[$key] = self::withoutBlanks($value);
            } elseif ($value !== null && $value !== '') {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
