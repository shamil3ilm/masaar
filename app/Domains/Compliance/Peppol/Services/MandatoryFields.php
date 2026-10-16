<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Contracts\JurisdictionProfile;
use App\Domains\Compliance\Peppol\DTOs\PintDocument;
use App\Domains\Compliance\Peppol\DTOs\PintLine;
use App\Domains\Compliance\Peppol\DTOs\PintParty;
use App\Domains\Compliance\Peppol\Enums\PintTaxCategory;
use App\Domains\Compliance\Peppol\Profiles\PintAe;
use Closure;

/**
 * The Ministry's mandatory-fields list, checked before a document exists.
 *
 * "UAE Electronic Invoice Mandatory Fields" v1.0 (23 Feb 2026) lists 51
 * fields for a tax invoice (4.1) and 49 for a commercial one (4.2). Each is
 * a named check below, in the list's order, so the two can be read side by
 * side. A field the specification makes conditional - a due date on a
 * credit note, a rate on an exempt line - is checked only where it applies,
 * and the schematron rule that says so is named beside it.
 *
 * The list does not cover the scenarios (free zone, export, reverse
 * charge...), and those fail validation just as surely, so scenario() adds
 * what each one requires. A document that fails here is never stored.
 */
class MandatoryFields
{
    /**
     * Everything missing or wrong, as sentences a person can act on.
     *
     * @return list<string>
     */
    public function problems(PintDocument $document, JurisdictionProfile $profile): array
    {
        $problems = [];

        foreach ($this->fieldsFor($document) as $field => $check) {
            $problem = $check($document);

            if ($problem !== null) {
                $problems[] = $problem === '' ? __(':field is missing.', ['field' => $field]) : $field.': '.$problem;
            }
        }

        return array_values(array_merge($problems, $this->scenario($document, $profile)));
    }

    /**
     * The list that applies, field name => check. A check returns null when
     * the field is right, '' when it is absent, or what is wrong with it.
     *
     * @return array<string, Closure(PintDocument): ?string>
     */
    public function fieldsFor(PintDocument $document): array
    {
        $commercial = $document->category->isCommercial();

        return array_merge(
            $this->invoiceDetails(),
            $this->seller($commercial),
            $this->buyer($commercial),
            $this->totals(),
            $this->lines($commercial),
        );
    }

    /** @return array<string, Closure(PintDocument): ?string> */
    private function invoiceDetails(): array
    {
        return [
            'Invoice number' => fn (PintDocument $d): ?string => self::present($d->number),
            'Invoice date' => fn (PintDocument $d): ?string => self::present($d->issueDate),
            'Invoice type code' => fn (PintDocument $d): ?string => self::present($d->typeCode),
            'Invoice currency code' => fn (PintDocument $d): ?string => preg_match('/^[A-Z]{3}$/', $d->currency) === 1 ? null : '',
            'Invoice transaction type code' => fn (PintDocument $d): ?string => preg_match('/^[01]{8}$/', $d->transactionFlags) === 1
                ? null : __('eight characters, each 0 or 1 (IBR-154-AE).'),
            // IBR-127-AE: not on a credit note or a deemed supply, nor when nothing is payable.
            'Payment due date' => fn (PintDocument $d): ?string => $d->category->isCreditNote()
                || $d->hasFlag(PintAe::FLAG_DEEMED_SUPPLY) || bccomp($d->payableAmount, '0', 2) <= 0
                    ? null : self::present($d->dueDate),
            'Business process type' => fn (PintDocument $d): ?string => self::present($d->profileId),
            'Specification identifier' => fn (PintDocument $d): ?string => self::present($d->customizationId),
            // IBR-191-AE: not on a credit note or a deemed supply.
            'Payment means type code' => fn (PintDocument $d): ?string => $d->category->isCreditNote() || $d->hasFlag(PintAe::FLAG_DEEMED_SUPPLY)
                ? null : self::present($d->paymentMeansCode),
        ];
    }

    /** @return array<string, Closure(PintDocument): ?string> */
    private function seller(bool $commercial): array
    {
        $fields = [
            'Seller name' => fn (PintDocument $d): ?string => self::present($d->seller->name),
            'Seller electronic address' => fn (PintDocument $d): ?string => self::endpointId($d->seller),
            'Seller electronic identifier' => fn (PintDocument $d): ?string => $d->seller->endpointScheme === PintAe::SCHEME
                ? null : __('the scheme is :scheme for a UAE business.', ['scheme' => PintAe::SCHEME]),
            'Seller legal registration identifier' => fn (PintDocument $d): ?string => self::present($d->seller->legalId),
            'Seller legal registration identifier type' => fn (PintDocument $d): ?string => self::legalType($d->seller),
        ];

        if ($commercial) {
            // 4.2 item 15: the TRN, or the TIN of a seller that has none.
            $fields['Seller tax registration identifier'] = fn (PintDocument $d): ?string => $d->seller->vatId !== null
                ? self::trn($d->seller->vatId)
                : ($d->seller->taxRegistrationId === null ? '' : self::tin($d->seller->taxRegistrationId));
        } else {
            $fields['Seller tax identifier'] = fn (PintDocument $d): ?string => $d->seller->vatId === null ? '' : self::trn($d->seller->vatId);
        }

        // Written by the builder beside the identifier: VAT, or TIN for a TIN.
        $fields['Seller tax scheme code'] = fn (PintDocument $d): ?string => null;

        return array_merge($fields, self::addressFields('Seller', fn (PintDocument $d): PintParty => $d->seller));
    }

    /** @return array<string, Closure(PintDocument): ?string> */
    private function buyer(bool $commercial): array
    {
        $fields = [
            'Buyer name' => fn (PintDocument $d): ?string => self::present($d->buyer->name),
            'Buyer electronic address' => fn (PintDocument $d): ?string => self::endpointId($d->buyer),
            'Buyer electronic identifier' => fn (PintDocument $d): ?string => self::present($d->buyer->endpointScheme),
        ];

        if ($commercial) {
            // IBR-136-AE.
            $fields['Buyer legal registration identifier'] = fn (PintDocument $d): ?string => self::present($d->buyer->legalId);
            $fields['Buyer legal registration identifier type'] = fn (PintDocument $d): ?string => self::legalType($d->buyer);
        } else {
            // A buyer abroad has no TRN to give (the export samples carry none).
            $fields['Buyer tax identifier'] = fn (PintDocument $d): ?string => $d->buyer->address->countryCode !== 'AE' || $d->hasFlag(PintAe::FLAG_EXPORT)
                ? ($d->buyer->vatId === null ? null : self::trn($d->buyer->vatId))
                : ($d->buyer->vatId === null ? '' : self::trn($d->buyer->vatId));
            $fields['Buyer tax scheme code'] = fn (PintDocument $d): ?string => null;
        }

        return array_merge($fields, self::addressFields('Buyer', fn (PintDocument $d): PintParty => $d->buyer));
    }

    /**
     * Address line 1, city, subdivision, country. In the UAE the
     * subdivision is an emirate's code (IBR-128-AE).
     *
     * @param  Closure(PintDocument): PintParty  $party
     * @return array<string, Closure(PintDocument): ?string>
     */
    private static function addressFields(string $who, Closure $party): array
    {
        return [
            "{$who} address line 1" => fn (PintDocument $d): ?string => self::present($party($d)->address->street),
            "{$who} city" => fn (PintDocument $d): ?string => self::present($party($d)->address->city),
            "{$who} country subdivision" => function (PintDocument $d) use ($party): ?string {
                $address = $party($d)->address;

                if ($address->countryCode === 'AE' && ! in_array($address->subdivision, PintAe::EMIRATES, true)) {
                    return $address->subdivision === '' ? '' : __('":value" is not an emirate code (:codes).', [
                        'value' => $address->subdivision, 'codes' => implode(', ', PintAe::EMIRATES),
                    ]);
                }

                return self::present($address->subdivision);
            },
            "{$who} country code" => fn (PintDocument $d): ?string => preg_match('/^[A-Z]{2}$/', $party($d)->address->countryCode) === 1 ? null : '',
        ];
    }

    /**
     * Totals and the breakdown are computed from the lines, so they are
     * present by construction; what can be wrong is a document with nothing
     * on it.
     *
     * @return array<string, Closure(PintDocument): ?string>
     */
    private function totals(): array
    {
        $computed = fn (PintDocument $d): ?string => $d->lines === [] ? __('the document has no lines.') : null;

        return [
            'Sum of invoice line net amount' => $computed,
            'Invoice total amount without tax' => $computed,
            'Invoice total tax amount' => $computed,
            'Invoice total amount with tax' => $computed,
            'Amount due for payment' => fn (PintDocument $d): ?string => bccomp($d->payableAmount, '0', 2) < 0
                ? __('it is negative; a reduction is a credit note.') : null,
            'Tax category taxable amount' => $computed,
            'Tax category tax amount' => $computed,
            'Tax category code' => $computed,
            'Tax category rate' => $computed,
        ];
    }

    /** @return array<string, Closure(PintDocument): ?string> */
    private function lines(bool $commercial): array
    {
        $fields = [
            'Invoice line identifier' => self::eachLine(fn (PintLine $l): bool => $l->id !== ''),
            'Invoiced quantity' => self::eachLine(fn (PintLine $l): bool => bccomp($l->quantity, '0', 3) > 0),
            'Unit of measure code' => self::eachLine(fn (PintLine $l): bool => $l->unitCode !== ''),
            'Invoice line net amount' => self::eachLine(fn (PintLine $l): bool => bccomp($l->netAmount, '0', 2) >= 0),
            'Item net price' => self::eachLine(fn (PintLine $l): bool => bccomp($l->price, '0', 6) >= 0),
            'Item gross price' => self::eachLine(fn (PintLine $l): bool => bccomp($l->price, '0', 6) >= 0),
            'Item price base quantity' => self::eachLine(fn (PintLine $l): bool => true),
            'Invoiced item tax category code' => self::eachLine(fn (PintLine $l): bool => true),
            // IBR-190-AE: a standard-rated line is at 5.00; E and O state none.
            'Invoiced item tax rate' => self::eachLine(fn (PintLine $l): bool => ! $l->category->hasRate()
                || ($l->rate !== null && ($l->category !== PintTaxCategory::Standard || bccomp($l->rate, '5', 2) === 0))),
        ];

        if (! $commercial) {
            // IBR-163-AE: an exempt line carries no VAT amount.
            $fields['VAT line amount in AED'] = self::eachLine(fn (PintLine $l): bool => $l->category === PintTaxCategory::Exempt || $l->vatAed !== null);
            $fields['Invoice line amount in AED'] = self::eachLine(fn (PintLine $l): bool => $l->totalAed !== '');
        }

        $fields['Item name'] = self::eachLine(fn (PintLine $l): bool => trim($l->name) !== '');
        $fields['Item description'] = self::eachLine(fn (PintLine $l): bool => trim($l->description) !== '');

        return $fields;
    }

    /**
     * What each scenario, category and kind of document adds.
     *
     * @return list<string>
     */
    private function scenario(PintDocument $d, JurisdictionProfile $profile): array
    {
        $problems = [];
        $allowed = $profile->allowedTaxCategories($d->category);
        $add = function (bool $wrong, string $message) use (&$problems): void {
            if ($wrong) {
                $problems[] = $message;
            }
        };

        foreach ($d->lines as $line) {
            $add(! in_array($line->category, $allowed, true), __('Line :line: a :kind cannot carry tax category :category.', [
                'line' => $line->id, 'kind' => str_replace('_', ' ', $d->category->value), 'category' => $line->category->value,
            ]));
            $add($line->category === PintTaxCategory::Exempt && ! in_array($line->exemptionCode, PintAe::EXEMPTION_REASONS, true),
                __('Line :line: an exempt line needs an exemption reason code (:codes).', ['line' => $line->id, 'codes' => implode(', ', PintAe::EXEMPTION_REASONS)]));

            if ($line->category === PintTaxCategory::ReverseCharge) {
                $add(! in_array($line->goodsType, PintAe::REVERSE_CHARGE_GOODS, true),
                    __('Line :line: a reverse-charge line names the type of goods (:codes).', ['line' => $line->id, 'codes' => implode(', ', PintAe::REVERSE_CHARGE_GOODS)]));
                $add($line->standardItemId === null, __('Line :line: a reverse-charge line needs the item\'s GTIN.', ['line' => $line->id]));
                $add($d->buyer->vatId === null, __('Line :line: reverse charge needs the buyer\'s TRN.', ['line' => $line->id]));
            }
        }

        $add($d->currency !== $profile->taxCurrency() && ($d->exchangeRate === null || bccomp($d->exchangeRate, '0', 6) <= 0),
            __('An invoice in :currency needs its exchange rate to AED.', ['currency' => $d->currency]));
        $add(bccomp($d->allowanceTotal, '0', 2) > 0 && $d->allowances === [],
            __('The document discount cannot fall on reverse-charge or margin-scheme lines. Reduce those lines\' prices instead.'));
        $add($d->paymentMeansCode === '30' && $d->paymentAccount === null,
            __('A credit transfer needs the account to pay into: set payment_account in the UAE compliance settings.'));
        $add(! self::buyerIdentified($d), __('A buyer that is not on e-invoicing needs its TRN on the invoice.'));

        $this->creditNote($d, $add);
        $this->flags($d, $add);

        return $problems;
    }

    /**
     * IBR-158-AE and IBR-055-AE.
     *
     * @param  Closure(bool, string): void  $add
     */
    private function creditNote(PintDocument $d, Closure $add): void
    {
        if (! $d->category->isCreditNote()) {
            return;
        }

        $add($d->creditReason !== null && ! in_array($d->creditReason, PintAe::CREDIT_REASONS, true),
            __('":reason" is not a credit note reason (:codes).', ['reason' => $d->creditReason, 'codes' => implode(', ', PintAe::CREDIT_REASONS)]));
        $add($d->typeCode === '381' && $d->creditReason === null,
            __('A tax credit note needs its reason code (:codes).', ['codes' => implode(', ', PintAe::CREDIT_REASONS)]));
        $add(in_array($d->typeCode, ['381', '81'], true) && $d->creditReason !== 'VD' && $d->billingReferences === [],
            __('A credit note names the invoice it corrects, unless it is a volume discount.'));
    }

    /**
     * What each transaction-type flag requires.
     *
     * @param  Closure(bool, string): void  $add
     */
    private function flags(PintDocument $d, Closure $add): void
    {
        $add($d->hasFlag(PintAe::FLAG_FREE_ZONE) && $d->beneficiaryId === null,
            __('A free zone supply names the beneficiary (beneficiary_id).'));
        $add($d->hasFlag(PintAe::FLAG_DISCLOSED_AGENT) && ($d->principalId === null || $d->principalId === $d->seller->vatId),
            __('Disclosed agent billing names the principal\'s TRN (principal_id), which is not the seller\'s.'));
        $add($d->hasFlag(PintAe::FLAG_SUMMARY) && $d->periodEnd === null,
            __('A summary invoice states its period: the supply date and supply end date.'));
        $add($d->hasFlag(PintAe::FLAG_MARGIN_SCHEME) && array_filter($d->lines, fn (PintLine $l): bool => $l->category !== PintTaxCategory::MarginScheme) !== [],
            __('Under the margin scheme every line is in tax category N.'));
        $add($d->hasFlag(PintAe::FLAG_EXPORT) && ($d->delivery === null || $d->delivery->countryCode === 'AE' || ! self::complete($d->delivery->street, $d->delivery->city, $d->delivery->subdivision)),
            __('An export states where the goods go outside the UAE: street, city and subdivision.'));
        $add($d->hasFlag(PintAe::FLAG_ECOMMERCE) && ($d->delivery === null || ! self::complete($d->delivery->street, $d->delivery->city, $d->delivery->subdivision)),
            __('An e-commerce supply states the delivery address: street, city and subdivision.'));
        $add($d->hasFlag(PintAe::FLAG_DEEMED_SUPPLY) && $d->buyer->endpointId !== PintAe::DEEMED_SUPPLY_BUYER,
            __('A deemed supply goes to the reserved endpoint :endpoint.', ['endpoint' => PintAe::SCHEME.':'.PintAe::DEEMED_SUPPLY_BUYER]));
        $add($d->category->isCommercial() && ($d->hasFlag(PintAe::FLAG_DEEMED_SUPPLY) || $d->hasFlag(PintAe::FLAG_MARGIN_SCHEME) || $d->hasFlag(PintAe::FLAG_SUMMARY)),
            __('A commercial document cannot be a deemed supply, margin scheme or summary invoice (IBR-157-AE).'));
    }

    /**
     * IBR-135-AE: a buyer whose endpoint is not its own TIN - one of the
     * reserved endpoints - is identified by its TRN, unless it is abroad.
     */
    private static function buyerIdentified(PintDocument $d): bool
    {
        return $d->hasFlag(PintAe::FLAG_EXPORT)
            || $d->buyer->endpointScheme !== PintAe::SCHEME
            || preg_match('/^1\d{9}$/', $d->buyer->endpointId) === 1
            || $d->buyer->vatId !== null
            || $d->category->isCommercial();
    }

    /**
     * @param  Closure(PintLine): bool  $valid
     * @return Closure(PintDocument): ?string
     */
    private static function eachLine(Closure $valid): Closure
    {
        return function (PintDocument $d) use ($valid): ?string {
            $failing = array_map(fn (PintLine $l): string => $l->id, array_values(array_filter($d->lines, fn (PintLine $l): bool => ! $valid($l))));

            return $failing === [] ? null : __('missing or wrong on line :lines.', ['lines' => implode(', ', $failing)]);
        };
    }

    private static function present(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? '' : null;
    }

    private static function complete(string ...$values): bool
    {
        return ! in_array('', array_map('trim', $values), true);
    }

    /** A UAE participant identifier is a 10-digit TIN, or a reserved 99... number. */
    private static function endpointId(PintParty $party): ?string
    {
        if ($party->endpointId === '') {
            return '';
        }

        return $party->endpointScheme !== PintAe::SCHEME || preg_match('/^\d{10}$/', $party->endpointId) === 1
            ? null : __('":value" is not a 10-digit identifier.', ['value' => $party->endpointId]);
    }

    /** IBR-173-AE, IBR-172-AE, IBR-012-AE: a known type, and the authority or passport country beside it. */
    private static function legalType(PintParty $party): ?string
    {
        if ($party->legalId === null) {
            return null;
        }

        if (! in_array($party->legalType, PintAe::LEGAL_REGISTRATION_TYPES, true)) {
            return $party->legalType === null ? '' : __('":value" is not one of :types.', ['value' => $party->legalType, 'types' => implode(', ', PintAe::LEGAL_REGISTRATION_TYPES)]);
        }

        if ($party->legalType === 'TL' && $party->legalAuthority === null) {
            return __('a trade licence names its issuing authority.');
        }

        if ($party->legalType === 'PAS' && preg_match('/^[A-Z]{2}$/', (string) $party->legalAuthority) !== 1) {
            return __('a passport names its issuing country as a two-letter code.');
        }

        return null;
    }

    /** IBR-132-AE: 15 digits, starting with 1 and ending with 03. */
    private static function trn(string $trn): ?string
    {
        return preg_match('/^1\d{12}03$/', $trn) === 1 ? null : __('":value" is not a TRN (15 digits, starting 1, ending 03).', ['value' => $trn]);
    }

    /** IBR-148-AE: 10 digits, starting with 1. */
    private static function tin(string $tin): ?string
    {
        return preg_match('/^1\d{9}$/', $tin) === 1 ? null : __('":value" is not a TIN (10 digits, starting 1).', ['value' => $tin]);
    }
}
