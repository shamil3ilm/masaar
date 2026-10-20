<?php

declare(strict_types=1);

namespace App\Domains\Support\DTOs;

/**
 * One feature request category, as the admin-edited list holds it
 * (RequestCategories): its name in both languages, its place, whether it is
 * retired, and whether a request in it names the other system.
 */
final readonly class RequestCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $labelAr,
        public int $position,
        public bool $retired = false,
        public bool $namesSystem = false,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  as the customization keeps it
     */
    public static function fromEntry(array $entry): self
    {
        return new self(
            key: (string) $entry['key'],
            label: (string) ($entry['label'] ?? $entry['key']),
            labelAr: (string) ($entry['label_ar'] ?? ''),
            position: (int) ($entry['position'] ?? 0),
            retired: (bool) ($entry['retired'] ?? false),
            namesSystem: (bool) ($entry['names_system'] ?? false),
        );
    }

    /** Its name in the reader's language. */
    public function name(): string
    {
        return app()->getLocale() === 'ar' && $this->labelAr !== '' ? $this->labelAr : $this->label;
    }
}
