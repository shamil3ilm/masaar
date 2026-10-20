<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use App\Domains\Platform\Customization\Catalogue;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Support\DTOs\RequestCategory;
use Closure;
use DomainException;

/**
 * What a feature request can be about - an integration, OCR and document
 * capture, reports, automation, something else - as an admin keeps the list
 * (Admin › Settings › Request categories).
 *
 * One customization (Catalogue::REQUEST_CATEGORIES), a list of entries
 * kept at the platform level: add one, rename it in both languages, move
 * it, retire it. A retired category is no longer offered; a request already
 * in it keeps it.
 */
class RequestCategories
{
    private const KEY = Catalogue::REQUEST_CATEGORIES;

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /**
     * Every category, retired ones too, in their order.
     *
     * @return list<RequestCategory>
     */
    public function all(): array
    {
        $categories = array_map(fn (array $entry): RequestCategory => RequestCategory::fromEntry($entry), (array) $this->customizations->get(self::KEY, Scope::platform()));

        usort($categories, fn (RequestCategory $a, RequestCategory $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);

        return $categories;
    }

    /**
     * The categories a new request may choose: not retired.
     *
     * @return list<RequestCategory>
     */
    public function offered(): array
    {
        return array_values(array_filter($this->all(), fn (RequestCategory $category): bool => ! $category->retired));
    }

    /**
     * @return list<string>
     */
    public function offeredKeys(): array
    {
        return array_map(fn (RequestCategory $category): string => $category->key, $this->offered());
    }

    public function find(string $key): ?RequestCategory
    {
        foreach ($this->all() as $category) {
            if ($category->key === $key) {
                return $category;
            }
        }

        return null;
    }

    /** A category's name in the reader's language, or its key once removed. */
    public function name(string $key): string
    {
        return $this->find($key)?->name() ?? $key;
    }

    /**
     * The rules for a category as the admin form sends it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(bool $adding): array
    {
        return [
            'key' => $adding ? ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,38}$/', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->find((string) $value) !== null) {
                    $fail('There is a category '.$value.' already.');
                }
            }] : ['prohibited'],
            'label' => ['required', 'string', 'max:80'],
            'label_ar' => ['required', 'string', 'max:80'],
            'names_system' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Add a category, or rename one. A new one goes to the end.
     *
     * @param  array<string, mixed>  $validated
     */
    public function save(string $key, array $validated, ?string $actorId): void
    {
        $categories = $this->entries();
        $existing = $categories[$key] ?? null;

        $categories[$key] = [
            'key' => $key,
            'label' => trim((string) $validated['label']),
            'label_ar' => trim((string) $validated['label_ar']),
            'names_system' => (bool) ($validated['names_system'] ?? false),
            'position' => $existing['position'] ?? ($categories === [] ? 1 : max(array_column($categories, 'position')) + 1),
            'retired' => $existing['retired'] ?? false,
        ];

        $this->store($categories, $actorId);
    }

    /** Retire a category, which stops it being offered, or bring it back. */
    public function retire(string $key, bool $retired, ?string $actorId): void
    {
        $categories = $this->entries();
        $categories[$key] ?? throw new DomainException('There is no category '.$key.'.');
        $categories[$key]['retired'] = $retired;

        $this->store($categories, $actorId);
    }

    /** Move a category one place up (-1) or down (+1) the list. */
    public function move(string $key, int $direction, ?string $actorId): void
    {
        $keys = array_map(fn (RequestCategory $category): string => $category->key, $this->all());
        $from = array_search($key, $keys, true);

        if ($from === false) {
            throw new DomainException('There is no category '.$key.'.');
        }

        $to = max(0, min(count($keys) - 1, $from + ($direction < 0 ? -1 : 1)));
        [$keys[$from], $keys[$to]] = [$keys[$to], $keys[$from]];
        $categories = $this->entries();

        foreach ($keys as $index => $each) {
            $categories[$each]['position'] = $index + 1;
        }

        $this->store($categories, $actorId);
    }

    /**
     * The list as it stands, by key.
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
     * @param  array<string, array<string, mixed>>  $categories
     */
    private function store(array $categories, ?string $actorId): void
    {
        $this->customizations->set(self::KEY, Level::Platform, Scope::platform(), array_values($categories), $actorId);
    }
}
