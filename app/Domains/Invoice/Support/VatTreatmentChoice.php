<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Compliance\Engine\Contracts\VatTreatments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A VAT treatment chosen as a default - on a saved product - from the
 * organization's country's code lists (VatTreatments): a category it has,
 * and a reason from that category. An invoice line still states its own,
 * checked by the country's InvoiceRules.
 */
final class VatTreatmentChoice
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(VatTreatments $treatments, Request $request): array
    {
        // A category that charges no VAT and has reasons of its own needs
        // one: every invoice line in it does (the country's InvoiceRules),
        // so it is asked once, here, rather than on each invoice.
        $needsReason = function () use ($treatments, $request): bool {
            $chosen = $request->input('tax_category');

            foreach ($treatments->categories() as $category) {
                if ($category->code === $chosen && ! $category->taxed) {
                    return array_filter($treatments->exemptions(), fn ($reason): bool => $reason->category === $chosen) !== [];
                }
            }

            return false;
        };

        return [
            'tax_category' => ['nullable', 'string', Rule::in(array_map(fn ($category): string => $category->code, $treatments->categories()))],
            'exempt_code' => ['nullable', 'string', Rule::requiredIf($needsReason), Rule::in(array_map(fn ($reason): string => $reason->code, $treatments->exemptions())),
                function (string $attribute, mixed $value, Closure $fail) use ($treatments, $request): void {
                    $reason = $treatments->exemption((string) $value);

                    if ($reason !== null && $reason->category !== $request->input('tax_category')) {
                        $fail(__(':code is a reason for tax category :category.', ['code' => $reason->code, 'category' => $reason->category]));
                    }
                }],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['exempt_code.required' => __('Choose the reason this product is zero-rated, exempt or out of scope. Every invoice line in that category needs one.')];
    }

    /**
     * The choice as it is kept: a category that charges no VAT keeps its
     * own rate (zero, or none stated), and no reason stays beside the
     * standard rate.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function kept(VatTreatments $treatments, array $validated): array
    {
        $category = null;

        foreach ($treatments->categories() as $candidate) {
            if ($candidate->code === ($validated['tax_category'] ?? null)) {
                $category = $candidate;
            }
        }

        return match (true) {
            $category === null => [...$validated, 'tax_category' => null, 'exempt_code' => null],
            $category->taxed => [...$validated, 'exempt_code' => null],
            default => [...$validated, 'tax_rate' => $category->rate ?? '0'],
        };
    }
}
