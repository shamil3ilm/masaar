<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Document;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Contracts\JurisdictionProfile;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintAddress;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintAllowance;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintCharge;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintDocument;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintLine;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintParty;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintTaxSubtotal;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\ComplianceProfile;
use DomainException;

/**
 * An invoice, as a PINT document.
 *
 * Where each value comes from:
 *
 *   The organization's UAE compliance profile settings: trn, tin,
 *   endpoint_id (as the service provider issued it, "0235:1234567890"),
 *   legal_registration_type (TL, EID, PAS, CD), legal_registration_id,
 *   legal_registration_authority (the licensing authority, or a passport's
 *   country), emirate (AUH, DXB, ...) and payment_account (an IBAN, for
 *   credit transfers).
 *
 *   The invoice's jurisdiction_data: document_category, transaction_flags
 *   (the eight-character code), buyer_endpoint, buyer_subdivision,
 *   buyer_legal_registration {type, id, authority}, credit_reason,
 *   beneficiary_id (free zone), principal_id (disclosed agent),
 *   delivery_address {street, city, subdivision, country_code, postal_code},
 *   and lines keyed by line position from 1, each {goods_type, item_id,
 *   hs_code} for what a reverse-charge line must name.
 *
 * The invoice's buyer fields describe the counterparty. On a self-billed
 * document the organization is the buyer and the counterparty the seller.
 *
 * Every amount is computed here from the lines, in bcmath. The UAE rounds at
 * the document total only, so a category's VAT stays exact and the total is
 * rounded once.
 */
class PintMapper
{
    private const SCALE = 6;

    /** Payment means that pay into an account, which the document then names (IBR-192-AE). */
    private const TRANSFERS = ['30', '31', '42', '58'];

    /**
     * @throws DomainException for a document the UAE has no kind of, or a
     *                         line in a category it does not use
     */
    /**
     * @param  string|null  $uuid  the document's own UUID (cbc:UUID), generated when it is prepared; checks that build no document leave it out
     */
    public function map(Invoice $invoice, ComplianceProfile $settings, JurisdictionProfile $profile, ?string $uuid = null): PintDocument
    {
        $data = (array) ($invoice->jurisdiction_data ?? []);
        $category = $this->category($invoice, $data);
        $flags = (string) ($data['transaction_flags'] ?? PintAe::NO_FLAGS);
        $currency = strtoupper((string) ($invoice->currency ?: $profile->taxCurrency()));
        $foreign = $currency !== $profile->taxCurrency();
        $rate = $foreign && $invoice->exchange_rate !== null ? self::trim((string) $invoice->exchange_rate) : null;

        $own = $this->ownParty($invoice, $settings, $profile);
        $counterparty = $this->counterparty($invoice, $data, $flags, $profile);
        [$seller, $buyer] = $category->isSelfBilled() ? [$counterparty, $own] : [$own, $counterparty];

        $lines = $this->lines($invoice, $data, $profile, $foreign ? ($rate ?? '0') : null);
        $discount = bcadd((string) ($invoice->discount_amount ?? '0'), '0', 2);
        $charges = $this->charges($invoice, $profile);
        [$allowances, $subtotals] = $this->breakdown($lines, $discount, $charges);

        $lineExtension = array_reduce($lines, fn (string $sum, PintLine $line): string => bcadd($sum, $line->netAmount, 2), '0');
        $chargeTotal = array_reduce($charges, fn (string $sum, PintCharge $charge): string => bcadd($sum, $charge->amount, 2), '0.00');
        // IBT-109: the lines, less the allowances, plus the charges.
        $exclusive = bcadd(bcsub($lineExtension, $discount, 2), $chargeTotal, 2);
        $tax = self::round(array_reduce($subtotals, fn (string $sum, PintTaxSubtotal $s): string => bcadd($sum, $s->taxAmount, self::SCALE), '0'));
        $inclusive = bcadd($exclusive, $tax, 2);
        $credit = $category->isCreditNote();
        $means = $credit ? null : (trim((string) $invoice->payment_means_code) ?: null);

        return new PintDocument(
            category: $category,
            customizationId: $profile->customizationId($category),
            profileId: $profile->profileId($category),
            typeCode: $profile->typeCode($category),
            transactionFlags: $flags,
            number: (string) $invoice->invoice_number,
            uuid: $uuid ?? (string) $invoice->id,
            issueDate: $invoice->issue_date->format('Y-m-d'),
            dueDate: $credit ? null : $invoice->due_date?->format('Y-m-d'),
            // IBR-141-AE: a VAT point date is before the issue date, and a
            // credit note has none (IBR-124-AE).
            taxPointDate: ! $credit && $invoice->supply_date !== null && $invoice->supply_date->lt($invoice->issue_date)
                ? $invoice->supply_date->format('Y-m-d') : null,
            periodStart: $invoice->supply_end_date !== null ? $invoice->supply_date?->format('Y-m-d') : null,
            periodEnd: $invoice->supply_end_date?->format('Y-m-d'),
            currency: $currency,
            taxCurrency: $foreign ? $profile->taxCurrency() : null,
            exchangeRate: $rate,
            billingReferences: array_values(array_filter([trim((string) $invoice->billing_ref)])),
            creditReason: $credit ? (self::text($data['credit_reason'] ?? null)) : null,
            seller: $seller,
            buyer: $buyer,
            beneficiaryId: self::text($data['beneficiary_id'] ?? null),
            principalId: self::text($data['principal_id'] ?? null),
            delivery: $this->delivery($data, $flags, $counterparty->address),
            paymentMeansCode: $means,
            paymentAccount: in_array($means, self::TRANSFERS, true) ? self::text($settings->setting('payment_account')) : null,
            allowances: $allowances,
            subtotals: $subtotals,
            lineExtensionAmount: $lineExtension,
            allowanceTotal: $discount,
            taxExclusiveAmount: $exclusive,
            taxAmount: $tax,
            taxInclusiveAmount: $inclusive,
            payableAmount: $inclusive,
            taxAmountInTaxCurrency: $foreign ? self::round(bcmul($tax, $rate ?? '0', self::SCALE)) : null,
            totalInTaxCurrency: $foreign ? self::round(bcmul($inclusive, $rate ?? '0', self::SCALE)) : null,
            lines: $lines,
            buyerReference: self::text($invoice->buyer_reference),
            orderReference: self::text($invoice->order_reference),
            contractReference: self::text($invoice->contract_reference),
            paymentTerms: self::text($invoice->payment_terms),
            charges: $charges,
            chargeTotal: $chargeTotal,
        );
    }

    /**
     * The charges on the whole document, each at its net, at the category
     * and rate it is made at: a rate as a line in that category states one
     * (ALIGNED-IBRP-S-07, -Z-07, -E-07, -O-07), and for an exempt one its
     * exemption code (IBR-169-AE).
     *
     * @return list<PintCharge>
     */
    private function charges(Invoice $invoice, JurisdictionProfile $profile): array
    {
        return $invoice->charges->map(function (InvoiceCharge $charge) use ($profile): PintCharge {
            $category = PintTaxCategory::fromStored($charge->tax_category)
                ?? throw new DomainException(__('A charge\'s ":category" is not a UAE tax category.', ['category' => $charge->tax_category]));

            return new PintCharge(
                amount: bcadd((string) $charge->net_amount, '0', 2),
                reasonCode: (string) $charge->reason_code,
                reason: (string) $charge->reason,
                percent: $charge->percent === null ? null : self::trim((string) $charge->percent),
                base: $charge->base_amount === null ? null : bcadd((string) $charge->base_amount, '0', 2),
                category: $category,
                rate: $this->rateOf($category, (string) $charge->tax_rate, $profile),
                exemptionCode: $category === PintTaxCategory::Exempt ? self::text($charge->exempt_code) : null,
            );
        })->values()->all();
    }

    /**
     * The rate a category states: a standard line's own, zero for a
     * zero-rated one, the standard rate where the category names it (reverse
     * charge, margin scheme), and none for exempt or out of scope.
     */
    private function rateOf(PintTaxCategory $category, string $rate, JurisdictionProfile $profile): ?string
    {
        return match (true) {
            $category === PintTaxCategory::Standard => self::trim($rate),
            $category === PintTaxCategory::ZeroRated => '0',
            $category->hasRate() => $profile->standardRate(),
            default => null,
        };
    }

    /**
     * The invoice's VAT breakdown alone, as map() computes it, without the
     * parties: what a summary of the period's sales needs. In the invoice's
     * own currency; each subtotal's tax is exact, not rounded.
     *
     * @return list<PintTaxSubtotal>
     *
     * @throws DomainException for a line in a category the UAE does not use
     */
    public function breakdownOf(Invoice $invoice, JurisdictionProfile $profile): array
    {
        $lines = $this->lines($invoice, (array) ($invoice->jurisdiction_data ?? []), $profile, null);

        return $this->breakdown($lines, bcadd((string) ($invoice->discount_amount ?? '0'), '0', 2), $this->charges($invoice, $profile))[1];
    }

    /**
     * The kind of document: named in jurisdiction_data, or read off the
     * invoice's own document type and self-billing flag.
     *
     * @param  array<string, mixed>  $data
     */
    private function category(Invoice $invoice, array $data): PintDocumentCategory
    {
        $type = $invoice->document_type ?? DocumentType::Invoice;

        if (! in_array($type, [DocumentType::Invoice, DocumentType::CreditNote], true)) {
            throw new DomainException(__('A UAE e-invoice is an invoice or a credit note. An increase is invoiced, not debited.'));
        }

        $credit = $type === DocumentType::CreditNote;
        $named = self::text($data['document_category'] ?? null);

        if ($named === null) {
            return match (true) {
                (bool) $invoice->is_self_billed => $credit ? PintDocumentCategory::SelfBilledCreditNote : PintDocumentCategory::SelfBilledInvoice,
                default => $credit ? PintDocumentCategory::TaxCreditNote : PintDocumentCategory::TaxInvoice,
            };
        }

        $category = PintDocumentCategory::tryFrom($named)
            ?? throw new DomainException(__('":category" is not a kind of UAE e-invoice.', ['category' => $named]));

        if ($category->isCreditNote() !== $credit) {
            throw new DomainException(__('The document category :category does not match the invoice, which is a :type.', [
                'category' => $category->value,
                'type' => $type->value,
            ]));
        }

        return $category;
    }

    /**
     * The organization, from its UAE compliance settings. A TIN is carried
     * only by a seller with no TRN (IBR-178-AE allows one beside the other,
     * and nothing needs both).
     */
    private function ownParty(Invoice $invoice, ComplianceProfile $settings, JurisdictionProfile $profile): PintParty
    {
        $organization = $invoice->org;
        [$scheme, $endpoint] = self::endpoint($settings->setting('endpoint_id'), $profile);
        $trn = self::text($settings->setting('trn'));

        return new PintParty(
            name: self::text($organization?->name) ?? (string) $organization?->name_ar,
            endpointScheme: $scheme,
            endpointId: $endpoint,
            address: new PintAddress(
                street: trim(implode(' ', array_filter([$organization?->building_number, $organization?->street]))),
                city: (string) $organization?->city,
                subdivision: strtoupper((string) $settings->setting('emirate')),
                countryCode: strtoupper((string) ($organization?->country ?: $profile->country())),
                additionalStreet: self::text($organization?->additional_street),
                postalCode: self::text($organization?->postal_code),
            ),
            vatId: $trn,
            taxRegistrationId: $trn === null ? self::text($settings->setting('tin')) : null,
            legalId: self::text($settings->setting('legal_registration_id')),
            legalType: self::text($settings->setting('legal_registration_type')),
            legalAuthority: self::text($settings->setting('legal_registration_authority')),
        );
    }

    /**
     * Whoever the invoice is with. A deemed supply always goes to the
     * reserved deemed-supply endpoint, and an export with no endpoint to the
     * reserved export one (Guidelines v1.1, 10.4).
     *
     * @param  array<string, mixed>  $data
     */
    private function counterparty(Invoice $invoice, array $data, string $flags, JurisdictionProfile $profile): PintParty
    {
        $address = (array) ($invoice->buyer_address ?? []);
        $registration = (array) ($data['buyer_legal_registration'] ?? []);
        $endpoint = self::text($data['buyer_endpoint'] ?? null);

        if (($flags[PintAe::FLAG_DEEMED_SUPPLY] ?? '0') === '1') {
            $endpoint = $profile->endpointScheme().':'.PintAe::DEEMED_SUPPLY_BUYER;
        } elseif ($endpoint === null && ($flags[PintAe::FLAG_EXPORT] ?? '0') === '1') {
            $endpoint = $profile->endpointScheme().':'.PintAe::EXPORT_BUYER;
        }

        [$scheme, $id] = self::endpoint($endpoint, $profile);
        $country = strtoupper((string) ($address['country_code'] ?? '') ?: $profile->country());
        $subdivision = (string) ($data['buyer_subdivision'] ?? '');

        return new PintParty(
            name: self::text($invoice->buyer_name) ?? (string) $invoice->buyer_name_ar,
            endpointScheme: $scheme,
            endpointId: $id,
            address: new PintAddress(
                street: trim(implode(' ', array_filter([$address['building_number'] ?? null, $address['street'] ?? null]))),
                city: (string) ($address['city'] ?? ''),
                subdivision: $country === $profile->country() ? strtoupper($subdivision) : $subdivision,
                countryCode: $country,
                postalCode: self::text($address['postal_code'] ?? null),
            ),
            vatId: self::text($invoice->buyer_vat_number),
            legalId: self::text($registration['id'] ?? null),
            legalType: self::text($registration['type'] ?? null),
            legalAuthority: self::text($registration['authority'] ?? null),
        );
    }

    /**
     * Where the goods go. Named in jurisdiction_data, or, for an export or
     * an e-commerce supply, which must state one (IBR-152-AE, IBR-142-AE),
     * the buyer's address.
     *
     * @param  array<string, mixed>  $data
     */
    private function delivery(array $data, string $flags, PintAddress $buyer): ?PintAddress
    {
        $named = (array) ($data['delivery_address'] ?? []);

        if ($named !== []) {
            return new PintAddress(
                street: (string) ($named['street'] ?? ''),
                city: (string) ($named['city'] ?? ''),
                subdivision: (string) ($named['subdivision'] ?? ''),
                countryCode: strtoupper((string) ($named['country_code'] ?? '')),
                postalCode: self::text($named['postal_code'] ?? null),
            );
        }

        $needs = ($flags[PintAe::FLAG_EXPORT] ?? '0') === '1' || ($flags[PintAe::FLAG_ECOMMERCE] ?? '0') === '1';

        return $needs ? $buyer : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $toAed  The exchange rate, for a document not in dirhams.
     * @return list<PintLine>
     */
    private function lines(Invoice $invoice, array $data, JurisdictionProfile $profile, ?string $toAed): array
    {
        $details = (array) ($data['lines'] ?? []);

        return $invoice->lines->values()->map(function (InvoiceLine $line, int $index) use ($details, $profile, $toAed): PintLine {
            $position = (string) ($index + 1);
            $extra = (array) ($details[$position] ?? []);
            $category = PintTaxCategory::fromStored($line->tax_category)
                ?? throw new DomainException(__('Line :line: ":category" is not a UAE tax category.', ['line' => $position, 'category' => $line->tax_category]));

            $quantity = bcadd((string) $line->quantity, '0', 3);
            $net = $line->net_amount !== null
                ? bcadd((string) $line->net_amount, '0', 2)
                : self::round(bcmul($quantity, (string) $line->unit_price, self::SCALE));
            $rate = $this->rateOf($category, (string) $line->tax_rate, $profile);
            $vat = $category->chargesTax() ? bcdiv(bcmul($net, (string) $rate, self::SCALE), '100', self::SCALE) : '0';
            $discount = bccomp((string) ($line->discount_amount ?? '0'), '0', 2) > 0 ? bcadd((string) $line->discount_amount, '0', 2) : null;
            $charges = $line->charges->map(fn (InvoiceCharge $charge): PintCharge => new PintCharge(
                amount: bcadd((string) $charge->amount, '0', 2),
                reasonCode: (string) $charge->reason_code,
                reason: (string) $charge->reason,
                percent: $charge->percent === null ? null : self::trim((string) $charge->percent),
                base: $charge->base_amount === null ? null : bcadd((string) $charge->base_amount, '0', 2),
            ))->values()->all();
            // IBR-147-AE: quantity times price, plus the line's charges, less
            // its discount, is the net; the price is found from what is left.
            $priced = bcsub(bcadd($net, (string) ($discount ?? '0'), 2), array_reduce($charges, fn (string $s, PintCharge $c): string => bcadd($s, $c->amount, 2), '0'), 2);

            return new PintLine(
                id: $position,
                name: self::text($line->item_name) ?? (string) $line->description,
                description: self::text($line->description) ?? (string) $line->item_name,
                quantity: self::trim($quantity),
                unitCode: UnitCode::normalise($line->unit_code),
                netAmount: $net,
                price: $this->price($quantity, (string) $line->unit_price, $priced),
                category: $category,
                rate: $rate,
                vatAed: $category === PintTaxCategory::Exempt ? null : self::inAed($vat, $toAed),
                totalAed: self::inAed(bcadd($net, $vat, self::SCALE), $toAed),
                exemptionCode: $category === PintTaxCategory::Exempt ? self::text($line->exempt_code) : null,
                exemptionReason: $category === PintTaxCategory::Exempt ? self::text($line->exempt_reason) : null,
                classificationCode: self::text($extra['hs_code'] ?? null) ?? self::text($line->class_code),
                standardItemId: self::text($extra['item_id'] ?? null),
                goodsType: self::text($extra['goods_type'] ?? null),
                discount: $discount,
                charges: $charges,
            );
        })->all();
    }

    /**
     * The net price. The stored unit price is rounded to the fils, so where
     * quantity times it does not land on the net the price is carried to six
     * places instead: IBR-147-AE checks the one against the other.
     */
    private function price(string $quantity, string $unitPrice, string $net): string
    {
        if (bccomp($quantity, '0', 3) <= 0 || self::round(bcmul($quantity, $unitPrice, self::SCALE)) === $net) {
            return bcadd($unitPrice, '0', 2);
        }

        return self::trim(bcdiv($net, $quantity, self::SCALE), 2);
    }

    /**
     * The VAT breakdown, and the document discount shared across it.
     *
     * Each allowance names one category (ALIGNED-IBRP-032), so a discount on
     * a mixed invoice is split in proportion to each category's net, the
     * remainder to the largest. Reverse-charge and margin-scheme lines take
     * no share: an allowance in N is refused (IBR-115-AE), and one in AE
     * states a zero rate that would leave the breakdown's base unreduced.
     *
     * A document charge adds to the base of the category it is made at
     * (ALIGNED-IBRP-S-08, -Z-08, -E-08, -O-08); one at a category no line
     * has is a breakdown of its own, which takes no share of the discount.
     *
     * @param  list<PintLine>  $lines
     * @param  list<PintCharge>  $charges
     * @return array{list<PintAllowance>, list<PintTaxSubtotal>}
     */
    private function breakdown(array $lines, string $discount, array $charges = []): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $key = $line->category->value.'|'.$line->rate;
            $groups[$key] ??= ['category' => $line->category, 'rate' => $line->rate, 'net' => '0', 'code' => null, 'reason' => null, 'charges' => '0'];
            $groups[$key]['net'] = bcadd($groups[$key]['net'], $line->netAmount, 2);
            $groups[$key]['code'] ??= $line->exemptionCode;
            $groups[$key]['reason'] ??= $line->exemptionReason;
        }

        $shares = $this->shares($groups, $discount);

        foreach ($charges as $charge) {
            $key = $charge->category?->value.'|'.$charge->rate;
            $groups[$key] ??= ['category' => $charge->category, 'rate' => $charge->rate, 'net' => '0', 'code' => $charge->exemptionCode, 'reason' => null, 'charges' => '0'];
            $groups[$key]['charges'] = bcadd($groups[$key]['charges'], $charge->amount, 2);
        }

        $allowances = [];
        $subtotals = [];

        foreach ($groups as $key => $group) {
            $share = $shares[$key] ?? '0';
            $taxable = bcadd(bcsub($group['net'], $share, 2), $group['charges'], 2);
            /** @var PintTaxCategory $category */
            $category = $group['category'];

            if (bccomp($share, '0', 2) !== 0) {
                // ALIGNED-IBRP-E-06 and Z-06 want an explicit zero rate, and
                // O-06 none at all.
                $allowances[] = new PintAllowance($share, $category, match ($category) {
                    PintTaxCategory::Standard => $group['rate'],
                    PintTaxCategory::OutOfScope => null,
                    default => '0',
                }, $group['code']);
            }

            $subtotals[] = new PintTaxSubtotal(
                category: $category,
                rate: $group['rate'],
                taxableAmount: $taxable,
                taxAmount: $category->chargesTax()
                    ? self::trim(bcdiv(bcmul($taxable, (string) $group['rate'], self::SCALE), '100', self::SCALE), 2)
                    : '0.00',
                exemptionCode: $group['code'],
                exemptionReason: $group['reason'],
            );
        }

        return [$allowances, $subtotals];
    }

    /**
     * @param  array<string, array{category: PintTaxCategory, net: string}>  $groups
     * @return array<string, string>
     */
    private function shares(array $groups, string $discount): array
    {
        $eligible = array_filter($groups, fn (array $g): bool => ! in_array($g['category'], [PintTaxCategory::ReverseCharge, PintTaxCategory::MarginScheme], true));
        $base = array_reduce($eligible, fn (string $sum, array $g): string => bcadd($sum, $g['net'], 2), '0');

        if (bccomp($discount, '0', 2) <= 0 || bccomp($base, '0', 2) <= 0) {
            return [];
        }

        $shares = [];
        $largest = null;

        foreach ($eligible as $key => $group) {
            $shares[$key] = self::round(bcdiv(bcmul($discount, $group['net'], self::SCALE), $base, self::SCALE));

            if ($largest === null || bccomp($group['net'], $eligible[$largest]['net'], 2) > 0) {
                $largest = $key;
            }
        }

        $shares[$largest] = bcadd($shares[$largest], bcsub($discount, array_reduce($shares, fn (string $s, string $v): string => bcadd($s, $v, 2), '0'), 2), 2);

        return $shares;
    }

    /**
     * "0235:1234567890" as scheme and identifier. A bare identifier is in
     * the country's own scheme.
     *
     * @return array{string, string}
     */
    private static function endpoint(mixed $value, JurisdictionProfile $profile): array
    {
        $value = trim((string) $value);

        if (str_contains($value, ':')) {
            [$scheme, $id] = explode(':', $value, 2);

            return [trim($scheme), trim($id)];
        }

        return [$profile->endpointScheme(), $value];
    }

    /** An amount in dirhams: exact when it already is, to the fils when converted. */
    private static function inAed(string $amount, ?string $toAed): string
    {
        return $toAed === null ? self::trim($amount, 2) : self::round(bcmul($amount, $toAed, self::SCALE));
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Round half away from zero. bcmath on its own truncates. */
    private static function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, str_starts_with($value, '-') ? '-'.$half : $half, $scale);
    }

    /** Trailing zeros off, keeping at least $keep decimals. */
    private static function trim(string $value, int $keep = 0): string
    {
        if (! str_contains($value, '.')) {
            return $keep > 0 ? $value.'.'.str_repeat('0', $keep) : $value;
        }

        [$whole, $fraction] = explode('.', $value, 2);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $keep, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
