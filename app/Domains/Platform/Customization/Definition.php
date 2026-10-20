<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

use Illuminate\Support\Str;

/**
 * One customization: what it holds, its shipped default, which levels may
 * set it, whether a level may lock it against the levels below, and how a
 * change to it is recorded.
 *
 * Types:
 *   bool     on or off
 *   choice   one of $options (value => label)
 *   int      a whole number between $min and $max, a multiple of $step
 *   entries  a list of keyed entries, merged by key down the levels: a level
 *            changes an entry above it, adds its own, or hides one
 *   id       one record's id (a UUID), which the screen that sets it checks
 *            is one the holder may choose
 *   record   a set of named $fields, merged field by field down the levels:
 *            a level keeps only the fields it changes, and the screen that
 *            sets it checks each field
 *   days     a list of whole numbers between $min and $max, each once, in
 *            order (the days a reminder goes out): typed as "3, 7, 14"
 *   decimal  an amount between $min and $max, kept as a string with two
 *            decimal places so no float rounds it
 *
 * $ownScreen marks one set on a screen of its own (the branch switcher),
 * left off the settings and preferences pages.
 *
 * $console marks a person's preference that belongs to the admin console,
 * left off the portal's preferences.
 *
 * $country marks one only some countries use: an organization is shown it
 * only where its country's module says it uses it
 * (Presentation::usesCustomization). The platform's default is always shown.
 *
 * $columns names a level whose value is kept in an existing column rather
 * than in the store - 'table.column', on the row the level's holder names -
 * so a setting that every issuing path already reads from its column keeps
 * being read there. Null in the column means "as the level above".
 *
 * $preset names the field of the organization's business type that
 * defaults it (Catalogue::BUSINESS_TYPES): between the platform's value and
 * the organization's own, so the type suggests and the organization decides.
 */
final readonly class Definition
{
    public const AUDIT_SECURITY = 'security';

    public const AUDIT_RECORD = 'record';

    public const AUDIT_NONE = 'none';

    /**
     * @param  list<Level>  $levels  the levels that may hold a value, broadest first
     * @param  array<string, string>  $options  for a choice: value => label
     * @param  array<string, string>  $columns  level value => 'table.column'
     * @param  list<string>  $fields  for entries: the fields an entry carries besides its key; for a record, its fields
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $help,
        public string $type,
        public mixed $default,
        public array $levels,
        public array $options = [],
        public bool $lockable = false,
        public bool $acrossOrganizations = false,
        public string $audit = self::AUDIT_RECORD,
        public array $columns = [],
        public ?int $min = null,
        public ?int $max = null,
        public int $step = 1,
        public array $fields = [],
        public bool $console = false,
        public bool $ownScreen = false,
        public bool $country = false,
        public ?string $preset = null,
    ) {}

    public function allows(Level $level): bool
    {
        return in_array($level, $this->levels, true);
    }

    /** The narrowest level that may set it: nothing below it to lock against. */
    public function deepest(): Level
    {
        return $this->levels[array_key_last($this->levels)];
    }

    /** The column a level is kept in, as [table, column], or null for the store. */
    public function column(Level $level): ?array
    {
        $column = $this->columns[$level->value] ?? null;

        return $column === null ? null : explode('.', $column, 2);
    }

    /**
     * The value input stands for, or null when it is not one this
     * customization can hold.
     */
    public function parse(mixed $raw): mixed
    {
        return match ($this->type) {
            'bool' => match (true) {
                in_array($raw, ['1', 1, true, 'true', 'on'], true) => true,
                in_array($raw, ['0', 0, false, 'false', 'off'], true) => false,
                default => null,
            },
            'choice' => is_scalar($raw) && array_key_exists((string) $raw, $this->options) ? (string) $raw : null,
            'int' => $this->parseInt($raw),
            'entries' => is_array($raw) ? array_values($raw) : null,
            'record' => is_array($raw) ? array_intersect_key($raw, array_flip($this->fields)) : null,
            'id' => is_string($raw) && Str::isUuid($raw) ? $raw : null,
            'days' => $this->parseDays($raw),
            'decimal' => $this->parseDecimal($raw),
            default => null,
        };
    }

    /** A value as a person reads it. */
    public function display(mixed $value): string
    {
        return match ($this->type) {
            'bool' => $value ? __('On') : __('Off'),
            'choice' => $this->options[(string) $value] ?? (string) $value,
            'entries' => __('Entries: :count', ['count' => is_array($value) ? count($value) : 0]),
            'record' => __('Fields set: :count', ['count' => is_array($value) ? count($value) : 0]),
            'days' => implode(', ', array_map('intval', (array) $value)),
            default => $value === null ? '' : (string) $value,
        };
    }

    /**
     * "3, 7, 14" or [3, 7, 14]: each day once, smallest first. Empty is a
     * list with no days - no reminder - which is a value, not a reset.
     *
     * @return list<int>|null
     */
    private function parseDays(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : preg_split('/[\s,]+/', trim($raw));
        }

        if (! is_array($raw)) {
            return null;
        }

        $days = [];

        foreach ($raw as $day) {
            $parsed = $this->parseInt($day);

            if ($parsed === null) {
                return null;
            }

            $days[$parsed] = $parsed;
        }

        ksort($days);

        return array_values($days);
    }

    private function parseDecimal(mixed $raw): ?string
    {
        if (! is_numeric($raw) || preg_match('/^\d+(\.\d{1,2})?$/', trim((string) $raw)) !== 1) {
            return null;
        }

        $value = (float) $raw;

        if (($this->min !== null && $value < $this->min) || ($this->max !== null && $value > $this->max)) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    private function parseInt(mixed $raw): ?int
    {
        if (! is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
            return null;
        }

        $value = (int) $raw;

        if (($this->min !== null && $value < $this->min) || ($this->max !== null && $value > $this->max) || $value % $this->step !== 0) {
            return null;
        }

        return $value;
    }
}
