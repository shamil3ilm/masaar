<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\DeemedSupplyRules;
use App\Domains\Compliance\Engine\DTOs\DisbursementTreatment;
use App\Domains\Compliance\Engine\DTOs\VoucherRules;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * What a country's law says about supplies that are invoiced differently
 * from a single sale: several deliveries in one summary invoice, a
 * contract billed in stages with retention, a cost paid on the customer's
 * behalf, a voucher, a deemed supply.
 *
 * Shared code gathers what is invoiced and writes the invoice request in
 * the fields every country's InvoiceRules take; the country adds its own
 * fields here and says what its law allows, citing its own texts, so the
 * document is then checked by its rules (CountryDraft) as any other is.
 */
interface SpecialSupplies
{
    /**
     * A summary tax invoice: several supplies to one customer within one
     * calendar month on one invoice, which states the period. The request
     * is given with its supply_date and supply_end_date set to the period;
     * the country adds the fields that make it a summary.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function summary(array $payload): array;

    /** The last day the summary invoice for a period ending on $periodEnd may be issued. */
    public function summaryIssueBy(CarbonImmutable $periodEnd): CarbonImmutable;

    /**
     * A stage of a contract, or the release of retention withheld from its
     * stages: one supply of a contract billed as it progresses. The country
     * adds what its document says of such a supply.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function progress(array $payload): array;

    /**
     * The latest day retention withheld from a stage invoiced on $invoiced
     * can wait: the tax on it falls due then even if it is neither invoiced
     * nor paid.
     */
    public function retentionDueBy(CarbonImmutable $invoiced): CarbonImmutable;

    /**
     * How a disbursement line is taxed and what the seller confirms for a
     * line to be one; null where the country's texts at hand do not say, so
     * no line may be one.
     */
    public function disbursement(): ?DisbursementTreatment;

    /** How a voucher with a face value is taxed when it is sold and when it is used. */
    public function vouchers(): VoucherRules;

    /** The thresholds under which giving goods or services away is not taxed. */
    public function deemedSupplies(): DeemedSupplyRules;

    /**
     * The invoice that states a deemed supply's output tax, kept by the
     * seller: the request with its one line at the deemed value, and the
     * recipient's name where there is one; the country adds what makes it a
     * deemed supply and who its document names as buyer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function deemedSupply(array $payload, Organization $organization): array;
}
