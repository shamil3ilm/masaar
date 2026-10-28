<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Invoice\Services\Catalogue;
use App\Domains\Invoice\Support\AdjustmentReason;
use App\Domains\Invoice\Support\DisbursementLines;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Http\Request;

/**
 * What a Saudi invoice draft says when the caller leaves it out: from the
 * saved customer and products it names, and from the codes it gives.
 */
final class DraftDefaults
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Catalogue $catalogue,
        private readonly SpecialSupplies $special,
    ) {}

    /**
     * Fill in what the caller left out, without contradicting what they
     * said. A line carrying an exemption code is not standard-rated, so
     * defaulting every line to S at 15% produced documents that claimed
     * both - which ZATCA rejects rather than reconciles. The code decides
     * the category, and an exempt line is not taxed.
     */
    public function defaults(Request $request): array
    {
        $defaults = [
            'document_type' => $request->input('document_type') ?? 'invoice',
            // Upper case, as ISO 4217 and the document have it. 'sar' was
            // stored as typed, slipped past the foreign-currency rule and
            // went out as 'sar', which the authority refuses.
            'currency' => is_string($request->input('currency')) ? strtoupper($request->input('currency')) : FatooraConfig::CURRENCY_SAR,
            'payment_means_code' => $request->input('payment_means_code') ?? '10',
        ];

        // A saved customer eligible for a citizen's zero rating: its lines
        // start in it, and it is named by the national ID it keeps
        // (BR-KSA-49), unless the invoice says otherwise.
        $citizen = is_string($request->input('customer_id')) && $this->organizationId() !== null
            ? $this->catalogue->customerWithExemption($this->organizationId(), $request->input('customer_id'))
            : null;

        if ($citizen !== null && blank($request->input('buyer_id'))) {
            $defaults['buyer_id'] = $citizen->buyer_id;
            $defaults['buyer_id_scheme'] = $citizen->buyer_id_scheme;
        }

        if ($request->has('lines')) {
            $defaults['lines'] = collect($request->input('lines'))->map(function ($line) use ($citizen) {
                $line = is_array($line) ? $this->catalogueTreatment($line, $citizen?->exempt_code) : $line;
                // A disbursement is out of scope whatever the line said
                // (SpecialSupplies::disbursement).
                $line = is_array($line) ? DisbursementLines::treated($line, $this->special->disbursement()) : $line;
                $exemptionCode = $line['exempt_code'] ?? null;

                $category = $line['tax_category']
                    ?? FatooraConfig::taxCategoryFor($exemptionCode, (float) ($line['tax_rate'] ?? InvoiceRules::STANDARD_RATE));

                $line = $this->productArabic(array_merge([
                    'unit_code' => UnitCode::DEFAULT,
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? (int) InvoiceRules::STANDARD_RATE : 0,
                ], $line));

                // A code with an official text states it as the reason
                // (BT-120) when the caller gives none: the authority's own
                // words, which the printed copy puts beside its Arabic.
                $official = is_string($exemptionCode) ? (FatooraConfig::EXEMPTION_REASONS_EN[$exemptionCode] ?? null) : null;

                if ($official !== null && blank($line['exempt_reason'] ?? null)) {
                    $line['exempt_reason'] = $official;
                }

                if (is_array($line['charges'] ?? null)) {
                    $line['charges'] = array_map(fn ($charge) => is_array($charge) ? self::chargeArabic($charge) : $charge, $line['charges']);
                }

                return $line;
            })->toArray();
        }

        // A reason ZATCA lists (KSA-10), sent in English, carries the Arabic
        // the data dictionary gives it.
        $official = AdjustmentReason::bilingual($request->input('adjustment_reason'));

        if ($official !== null) {
            $defaults['adjustment_reason'] = $official;
        }

        // A saved customer's payment terms, when the invoice names the
        // customer and gives none.
        if (blank($request->input('payment_terms')) && is_string($request->input('customer_id')) && $this->organizationId() !== null) {
            $terms = $this->catalogue->customerPaymentTerms($this->organizationId(), $request->input('customer_id'));

            if ($terms !== null) {
                $defaults['payment_terms'] = $terms;
            }
        }

        // A saved customer's Arabic name, when the invoice names the
        // customer and not the Arabic (ArabicText).
        if (blank($request->input('buyer_name_ar')) && is_string($request->input('customer_id'))) {
            $arabic = $this->organizationId() === null ? null
                : $this->catalogue->customerArabicName($this->organizationId(), $request->input('customer_id'));

            if ($arabic !== null) {
                $defaults['buyer_name_ar'] = $arabic;
            }
        }

        // A document charge is made at the standard rate unless it says
        // otherwise; one in another category is not taxed.
        if (is_array($request->input('charges'))) {
            $defaults['charges'] = array_map(function ($charge) {
                if (! is_array($charge)) {
                    return $charge;
                }

                $category = $charge['tax_category'] ?? FatooraConfig::TAX_CATEGORY_STANDARD;

                return self::chargeArabic([
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? (int) InvoiceRules::STANDARD_RATE : 0,
                    ...$charge,
                ]);
            }, $request->input('charges'));
        }

        return $defaults;
    }

    /**
     * A charge whose reason has no Arabic takes the Arabic for its reason
     * code beside it, where the code has one (FatooraConfig::
     * CHARGE_REASONS_AR); otherwise it is left for ArabicText to refuse.
     *
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>
     */
    private static function chargeArabic(array $charge): array
    {
        $reason = $charge['reason'] ?? null;
        $arabic = FatooraConfig::CHARGE_REASONS_AR[strtoupper((string) ($charge['reason_code'] ?? 'ABK'))] ?? null;

        if (! is_string($reason) || trim($reason) === '' || ArabicText::isArabic($reason) || $arabic === null) {
            return $charge;
        }

        return [...$charge, 'reason' => mb_substr(trim($reason).' / '.$arabic, 0, 255)];
    }

    /**
     * A line naming a saved product, by its id or its code, takes the
     * product's Arabic name when it gives none of its own. Never a machine
     * translation: only what the organization wrote down for the product.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function productArabic(array $line): array
    {
        if (filled($line['description_ar'] ?? null) || $this->organizationId() === null) {
            return $line;
        }

        $arabic = $this->catalogue->productArabicName(
            $this->organizationId(),
            is_string($line['product_id'] ?? null) ? $line['product_id'] : null,
            is_string($line['product_code'] ?? null) ? $line['product_code'] : null,
        );

        return $arabic === null ? $line : [...$line, 'description_ar' => $arabic];
    }

    /**
     * A line that says nothing of its VAT - no category, no reason, no
     * rate - starts in its saved product's treatment, or failing one, the
     * zero rating its customer is eligible for. A product that names its
     * treatment keeps it for every buyer: a citizen's sunscreen is still
     * standard-rated. A line that says anything keeps what it says.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function catalogueTreatment(array $line, ?string $customerCode): array
    {
        if (filled($line['tax_category'] ?? null) || filled($line['exempt_code'] ?? null) || filled($line['tax_rate'] ?? null) || $this->organizationId() === null) {
            return $line;
        }

        $product = $this->catalogue->productNamed(
            $this->organizationId(),
            is_string($line['product_id'] ?? null) ? $line['product_id'] : null,
            is_string($line['product_code'] ?? null) ? $line['product_code'] : null,
        );

        return match (true) {
            filled($product?->tax_category) => [...$line, 'tax_category' => $product->tax_category, 'exempt_code' => $product->exempt_code, 'tax_rate' => (string) $product->tax_rate],
            $customerCode !== null => [...$line, 'exempt_code' => $customerCode],
            default => $line,
        };
    }

    private function organizationId(): ?string
    {
        return $this->tenant->getOrganizationId();
    }
}
