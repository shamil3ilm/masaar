<?php

declare(strict_types=1);

namespace App\Domains\Organization\DTOs;

/**
 * One business type, as the admin-editable list holds it (BusinessTypes):
 * its name in both languages, its place and whether it is retired, and the
 * defaults it suggests. Every default may be null - "no opinion" - and none
 * of them refuses anything.
 *
 * $buyerFields is 'minimal' (the buyer's name to hand, the rest folded away,
 * as at a till) or 'full'. $vatHints are treatment codes the business
 * commonly needs, offered first where its country has them.
 * $dashboardPanels and $setupSteps are keys from BusinessTypes::PANELS and
 * BusinessTypes::STEPS. $insurerSplit opens the invoice form on splitting
 * a sale with an insurer (InsuranceSplits), as a pharmacy or a clinic bills.
 * $note is the country's word on the type, where it has one (Sectors::note).
 */
final readonly class BusinessType
{
    /**
     * @param  list<string>  $vatHints
     * @param  list<string>  $dashboardPanels
     * @param  list<string>  $setupSteps
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $labelAr,
        public int $position,
        public bool $retired = false,
        public ?string $invoiceType = null,
        public ?bool $pricesIncludeVat = null,
        public ?string $paymentMeans = null,
        public string $buyerFields = 'full',
        public ?string $paper = null,
        public array $vatHints = [],
        public array $dashboardPanels = [],
        public array $setupSteps = [],
        public ?string $note = null,
        public bool $insurerSplit = false,
        // How often its schedules bill unless they say otherwise.
        public ?string $scheduleFrequency = null,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  as the customization keeps it
     */
    public static function fromEntry(array $entry): self
    {
        $list = fn (string $field): array => array_values(array_filter(array_map('strval', (array) ($entry[$field] ?? [])), 'filled'));
        $text = fn (string $field): ?string => filled($entry[$field] ?? null) ? (string) $entry[$field] : null;

        return new self(
            key: (string) $entry['key'],
            label: (string) ($entry['label'] ?? $entry['key']),
            labelAr: (string) ($entry['label_ar'] ?? ''),
            position: (int) ($entry['position'] ?? 0),
            retired: (bool) ($entry['retired'] ?? false),
            invoiceType: $text('invoice_type'),
            pricesIncludeVat: isset($entry['prices_include_vat']) && $entry['prices_include_vat'] !== '' ? (bool) $entry['prices_include_vat'] : null,
            paymentMeans: $text('payment_means'),
            buyerFields: ($entry['buyer_fields'] ?? 'full') === 'minimal' ? 'minimal' : 'full',
            paper: $text('paper'),
            vatHints: $list('vat_hints'),
            dashboardPanels: $list('dashboard_panels'),
            setupSteps: $list('setup_steps'),
            insurerSplit: (bool) ($entry['insurer_split'] ?? false),
            scheduleFrequency: $text('schedule_frequency'),
        );
    }

    /** Its name in the reader's language. */
    public function name(): string
    {
        if ($this->labelAr === '') {
            return $this->label;
        }

        return app()->getLocale() === 'ar' ? $this->labelAr : $this->label;
    }

    public function noted(?string $note): self
    {
        return new self($this->key, $this->label, $this->labelAr, $this->position, $this->retired, $this->invoiceType,
            $this->pricesIncludeVat, $this->paymentMeans, $this->buyerFields, $this->paper, $this->vatHints,
            $this->dashboardPanels, $this->setupSteps, $note, $this->insurerSplit, $this->scheduleFrequency);
    }
}
