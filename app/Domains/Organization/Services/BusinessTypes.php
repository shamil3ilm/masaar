<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Organization\DTOs\BusinessType;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Catalogue;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Closure;
use DomainException;
use Illuminate\Validation\Rule;

/**
 * The business types an organization says it is - retail, pharmacy, school,
 * exporter - and the defaults each suggests.
 *
 * One customization (Catalogue::BUSINESS_TYPES), a list of entries: the
 * platform's, which an admin edits (add, rename in both languages, reorder,
 * retire, change the defaults), and an organization's own over it. A type
 * sets defaults only: the invoice type and payment means a form starts on,
 * whether prices include VAT and the paper (both through the customizations
 * they default, Definition $preset), which buyer fields are to hand, the
 * VAT treatments offered first, the dashboard's shortcuts and the setup
 * steps. It never refuses a feature, so a mixed business is never locked
 * out of anything.
 *
 * Each country says which types its e-invoicing serves (Sectors): one it
 * leaves out is not offered there, one it serves in part carries its note.
 */
class BusinessTypes
{
    private const KEY = Catalogue::BUSINESS_TYPES;

    /** The shortcuts a dashboard can show, by key: where each goes. */
    public const PANELS = [
        'new_invoice' => 'portal.invoices.create',
        'products' => 'portal.products.index',
        'customers' => 'portal.customers.index',
        'reports' => 'portal.reports',
        'daily_close' => 'portal.reports.close',
        'printing' => 'portal.settings.edit',
    ];

    /** The optional setup steps a type can add (SetupChecklist). */
    public const STEPS = ['products', 'customers', 'printing'];

    public const INVOICE_TYPES = ['simplified', 'standard'];

    public const BUYER_FIELDS = ['minimal', 'full'];

    public function __construct(
        private readonly Customizations $customizations,
        private readonly AuditService $audit,
    ) {}

    /**
     * Every type, retired ones too, in their order: the platform's list, or
     * an organization's where it has its own.
     *
     * @return list<BusinessType>
     */
    public function all(?string $organizationId = null): array
    {
        $scope = $organizationId === null ? Scope::platform() : Scope::organization($organizationId);
        $types = array_map(fn (array $entry): BusinessType => BusinessType::fromEntry($entry), (array) $this->customizations->get(self::KEY, $scope));

        usort($types, fn (BusinessType $a, BusinessType $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);

        return $types;
    }

    /**
     * The types offered in a country: not retired, not left out by the
     * country, each with the country's note - and the one already chosen,
     * whatever has become of it, so a form never drops it silently.
     *
     * @return list<BusinessType>
     */
    public function offered(Jurisdiction $country, ?string $current = null, ?string $organizationId = null): array
    {
        $sectors = $country->sectors();
        $omitted = $sectors->omitted();
        $offered = [];

        foreach ($this->all($organizationId) as $type) {
            if ($type->key === $current || (! $type->retired && ! in_array($type->key, $omitted, true))) {
                $offered[] = $type->noted($sectors->note($type->key));
            }
        }

        return $offered;
    }

    /**
     * @return list<string>
     */
    public function offeredKeys(Jurisdiction $country, ?string $current = null, ?string $organizationId = null): array
    {
        return array_map(fn (BusinessType $type): string => $type->key, $this->offered($country, $current, $organizationId));
    }

    public function find(string $key, ?string $organizationId = null): ?BusinessType
    {
        foreach ($this->all($organizationId) as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    /** The type an organization chose; null when it chose none, or one since removed. */
    public function of(?Organization $organization): ?BusinessType
    {
        return filled($organization?->business_type) ? $this->find((string) $organization->business_type, (string) $organization->id) : null;
    }

    /**
     * The dashboard's shortcuts for a type: where each goes, and what it is
     * called. None without a type.
     *
     * @return list<array{key: string, route: string, label: string}>
     */
    public function shortcuts(?BusinessType $type): array
    {
        $labels = [
            'new_invoice' => __('New invoice'),
            'products' => __('Products'),
            'customers' => __('Customers'),
            'reports' => __('Reports'),
            'daily_close' => __('Daily close'),
            'printing' => __('Printing'),
        ];

        return array_values(array_map(
            fn (string $key): array => ['key' => $key, 'route' => self::PANELS[$key], 'label' => $labels[$key]],
            array_filter($type?->dashboardPanels ?? [], fn (string $key): bool => isset(self::PANELS[$key])),
        ));
    }

    /** The organization says what it is, or that it would rather not. */
    public function choose(Organization $organization, ?string $key, ?string $actorId): void
    {
        $before = $organization->business_type;
        $organization->forceFill(['business_type' => filled($key) ? $key : null])->save();

        $this->audit->logChange('organization.business_type', 'Organization', (string) $organization->id,
            ['business_type' => $before], ['business_type' => $organization->business_type], [], $actorId);
    }

    /**
     * The rules for a type as the admin form sends it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(bool $adding): array
    {
        return [
            'key' => $adding ? ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,38}$/', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->find((string) $value) !== null) {
                    $fail(__('There is a business type :key already.', ['key' => (string) $value]));
                }
            }] : ['prohibited'],
            'label' => ['required', 'string', 'max:80'],
            'label_ar' => ['required', 'string', 'max:80'],
            'invoice_type' => ['nullable', Rule::in(self::INVOICE_TYPES)],
            'prices_include_vat' => ['nullable', Rule::in(['0', '1'])],
            'payment_means' => ['nullable', 'string', 'regex:/^[A-Z0-9]{1,3}$/'],
            'buyer_fields' => ['required', Rule::in(self::BUYER_FIELDS)],
            'paper' => ['nullable', Rule::enum(PaperSize::class)],
            'schedule_frequency' => ['nullable', Rule::enum(ScheduleFrequency::class)],
            'vat_hints' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9.\-,\s]*$/'],
            'dashboard_panels' => ['nullable', 'array'],
            'dashboard_panels.*' => [Rule::in(array_keys(self::PANELS))],
            'setup_steps' => ['nullable', 'array'],
            'setup_steps.*' => [Rule::in(self::STEPS)],
            'insurer_split' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The form's values as an entry keeps them: no opinion as null, the
     * hints as a list of codes.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function entryFrom(array $validated): array
    {
        return [
            'label' => trim((string) $validated['label']),
            'label_ar' => trim((string) $validated['label_ar']),
            'invoice_type' => $validated['invoice_type'] ?? null,
            'prices_include_vat' => isset($validated['prices_include_vat']) ? $validated['prices_include_vat'] === '1' : null,
            'payment_means' => $validated['payment_means'] ?? null,
            'buyer_fields' => $validated['buyer_fields'],
            'paper' => $validated['paper'] ?? null,
            'schedule_frequency' => $validated['schedule_frequency'] ?? null,
            'vat_hints' => array_values(array_filter(array_map('trim', explode(',', (string) ($validated['vat_hints'] ?? ''))))),
            'dashboard_panels' => array_values((array) ($validated['dashboard_panels'] ?? [])),
            'setup_steps' => array_values((array) ($validated['setup_steps'] ?? [])),
            'insurer_split' => (bool) ($validated['insurer_split'] ?? false),
        ];
    }

    /**
     * Add a type, or change one, on the platform's list: its names and its
     * defaults. A new one goes to the end.
     *
     * @param  array<string, mixed>  $entry  key and BUSINESS_TYPE_FIELDS
     */
    public function save(array $entry, ?string $actorId): void
    {
        $types = $this->entries();
        $key = (string) $entry['key'];
        $existing = $types[$key] ?? null;

        $types[$key] = [
            ...array_intersect_key($entry, array_flip(Catalogue::BUSINESS_TYPE_FIELDS)),
            'key' => $key,
            'position' => $existing['position'] ?? (count($types) === 0 ? 1 : max(array_column($types, 'position')) + 1),
            'retired' => $existing['retired'] ?? false,
        ];

        $this->store($types, $actorId);
    }

    /** Retire a type, which stops it being offered, or bring it back. */
    public function retire(string $key, bool $retired, ?string $actorId): void
    {
        $types = $this->entries();
        $types[$key] ?? throw new DomainException(__('There is no business type :key.', ['key' => $key]));
        $types[$key]['retired'] = $retired;

        $this->store($types, $actorId);
    }

    /** Move a type one place up (-1) or down (+1) the list. */
    public function move(string $key, int $direction, ?string $actorId): void
    {
        $keys = array_map(fn (BusinessType $type): string => $type->key, $this->all());
        $from = array_search($key, $keys, true);

        if ($from === false) {
            throw new DomainException(__('There is no business type :key.', ['key' => $key]));
        }

        $to = max(0, min(count($keys) - 1, $from + ($direction < 0 ? -1 : 1)));
        [$keys[$from], $keys[$to]] = [$keys[$to], $keys[$from]];
        $types = $this->entries();

        foreach ($keys as $index => $each) {
            $types[$each]['position'] = $index + 1;
        }

        $this->store($types, $actorId);
    }

    /**
     * The platform's list as it stands, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(): array
    {
        $entries = [];

        foreach ((array) $this->customizations->get(self::KEY, Scope::platform()) as $entry) {
            $entries[(string) $entry['key']] = $entry;
        }

        return $entries;
    }

    /**
     * The whole list, kept at the platform level: every entry complete, so
     * the list reads the same whichever entries the seed still has.
     *
     * @param  array<string, array<string, mixed>>  $types
     */
    private function store(array $types, ?string $actorId): void
    {
        $this->customizations->set(self::KEY, Level::Platform, Scope::platform(), array_values($types), $actorId);
    }
}
