<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

/**
 * The published roll-out of UAE e-invoicing, shown as a hint beside an
 * organization's own start date - never used in its place: which phase a
 * business is in depends on its revenue, which the platform does not know,
 * and a business may start early.
 *
 * UAE Electronic Invoicing Guidelines v1.1: voluntary implementation from
 * 1 July 2026 (8.2); the mandatory phases, each with its last date to
 * appoint a service provider and to implement (8.3, the table on p19), set
 * by Ministerial Decision No. 244 of 2025.
 */
final class EinvoicingPhases
{
    /** Anyone may start voluntarily from this date (8.2). */
    public const VOLUNTARY_FROM = '2026-07-01';

    /**
     * Who, the last date to appoint a provider, and the date e-invoicing is
     * mandatory (8.3).
     *
     * @var list<array{who: string, appoint_by: string, starts_on: string}>
     */
    public const MANDATORY = [
        ['who' => 'Businesses with annual revenue of AED 50 million or more', 'appoint_by' => '2026-07-31', 'starts_on' => '2027-01-01'],
        ['who' => 'Businesses with annual revenue below AED 50 million', 'appoint_by' => '2027-03-31', 'starts_on' => '2027-07-01'],
        ['who' => 'Government entities', 'appoint_by' => '2027-03-31', 'starts_on' => '2027-10-01'],
    ];
}
