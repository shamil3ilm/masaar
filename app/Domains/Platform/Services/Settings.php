<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Audit\Attribution\Origin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The operational numbers, editable from the admin console.
 *
 * These decide when the dashboard calls something a problem and when a
 * certificate expiry is worth telling someone about. They were private
 * consts in IssueDetector and literals in config/fatoora.php, so changing
 * one - for a value whose right setting is only learned by watching the
 * platform run - meant editing PHP and shipping a release.
 *
 * DEFINITIONS is the contract. A row in `settings` only overrides a default,
 * so a key that has never been saved still has a value, and deleting a row
 * restores the shipped one. A definition that is removed takes its stored
 * row out of circulation without a migration.
 *
 * Read on every admin dashboard render, so the whole map is cached as one
 * entry and forgotten on write rather than read key by key.
 */
final class Settings
{
    private const CACHE_KEY = 'platform.settings';

    public function __construct(private readonly Origin $origin) {}

    /** Channels a notification may be sent on. */
    public const CHANNELS = ['mail', 'slack', 'webhook'];

    /**
     * Every setting, with the bounds that make it meaningful.
     *
     * 'min' and 'max' are inclusive. 'after' names a setting this one must be
     * greater than - a critical threshold below its warning threshold inverts
     * the severity logic silently, so it is refused rather than stored.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFINITIONS = [
        'alerts.backlog_warning' => [
            'group' => 'Queue',
            'label' => 'Backlog warning',
            'help' => 'Queued invoices before the dashboard raises a warning.',
            'type' => 'int',
            'default' => 100,
            'min' => 1,
            'max' => 100000,
        ],
        'alerts.backlog_critical' => [
            'group' => 'Queue',
            'label' => 'Backlog critical',
            'help' => 'Queued invoices before that warning becomes critical.',
            'type' => 'int',
            'default' => 500,
            'min' => 1,
            'max' => 100000,
            'after' => 'alerts.backlog_warning',
        ],
        'alerts.failed_critical' => [
            'group' => 'Queue',
            'label' => 'Failed items critical',
            'help' => 'Failed queue items before the warning becomes critical.',
            'type' => 'int',
            'default' => 50,
            'min' => 1,
            'max' => 100000,
        ],
        'alerts.rejections_warning' => [
            'group' => 'Submissions',
            'label' => 'Rejections warning',
            'help' => 'Rejections in twenty-four hours before the dashboard says so.',
            'type' => 'int',
            'default' => 10,
            'min' => 1,
            'max' => 100000,
        ],
        'certificates.notifications_enabled' => [
            'group' => 'Certificates',
            'label' => 'Warn about expiring certificates',
            'help' => 'Turn expiry warnings off entirely. Submissions are still refused on an expired certificate.',
            'type' => 'bool',
            'default' => true,
        ],
        'certificates.notify_at_days' => [
            'group' => 'Certificates',
            'label' => 'Warn this many days before expiry',
            'help' => 'Days before a certificate expires, as a list. 30, 14, 7, 3, 1.',
            'type' => 'days',
            'default' => [30, 14, 7, 3, 1],
        ],
        'certificates.channels' => [
            'group' => 'Certificates',
            'label' => 'Send expiry warnings by',
            'help' => 'One or more of mail, slack, webhook.',
            'type' => 'channels',
            'default' => ['mail', 'webhook'],
        ],
        'site.easter_eggs' => [
            'group' => 'Public site',
            'label' => 'Small signs of the parent brand',
            'help' => 'On the public pages only: the coral period at the end of the footer, the note shown when someone types jlmk, the line on the not-found page and one line in the browser console. Never on invoices, documents, emails or the portal and console.',
            'type' => 'bool',
            'default' => true,
        ],
        'site.contact_url' => [
            'group' => 'Public site',
            'label' => 'Contact goes to',
            'help' => 'Empty: the home page\'s own contact section. A full https address sends its contact action there instead.',
            'type' => 'url',
            'default' => '',
        ],
    ];

    /**
     * One setting's current value.
     *
     * An unknown key is a programming error rather than a missing setting, so
     * it throws instead of returning null and letting a threshold of nothing
     * compare as zero - which would make every backlog critical.
     */
    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            throw new \InvalidArgumentException("No such setting: {$key}");
        }

        return $this->all()[$key];
    }

    /**
     * Every setting, stored value or shipped default.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->read());

        $values = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = $stored[$key] ?? $definition['default'];
        }

        return $values;
    }

    /**
     * Save what changed, having checked all of it first.
     *
     * Validated as a set rather than one at a time, because 'after' compares
     * two settings: raising both backlog thresholds in one submission is
     * legitimate, and checking each against the stored value would refuse the
     * half of it that arrived first.
     *
     * @param  array<string, mixed>  $input
     * @return list<string> the problems, empty when it saved
     */
    public function put(array $input, ?string $actor = null): array
    {
        $values = $this->all();
        $clean = [];
        $problems = [];

        foreach ($input as $key => $raw) {
            if (! array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }

            $parsed = $this->parse(self::DEFINITIONS[$key], $raw);

            if ($parsed === null) {
                $problems[] = self::DEFINITIONS[$key]['label'].' is not a valid value.';

                continue;
            }

            $clean[$key] = $parsed;
            $values[$key] = $parsed;
        }

        foreach ($clean as $key => $value) {
            $definition = self::DEFINITIONS[$key];
            $after = $definition['after'] ?? null;

            if ($after !== null && $value <= $values[$after]) {
                $problems[] = $definition['label'].' must be greater than '
                    .self::DEFINITIONS[$after]['label'].'.';
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($clean, $actor): void {
            foreach ($clean as $key => $value) {
                // Who set it first, and when, is kept: only the value and
                // who last changed it move.
                DB::table('settings')->upsert(
                    [[
                        'key' => $key,
                        'value' => json_encode($value),
                        'created_by' => $this->origin->actorId(),
                        'updated_by' => $actor,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['key'],
                    ['value', 'updated_by', 'updated_at'],
                );
            }
        });

        Cache::forget(self::CACHE_KEY);

        return [];
    }

    /**
     * What a form field should show for a setting.
     */
    public function display(string $key): string
    {
        $value = $this->get($key);

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return mixed null when the input cannot be used
     */
    private function parse(array $definition, mixed $raw): mixed
    {
        return match ($definition['type']) {
            'bool' => $this->parseBool($raw),
            'int' => $this->parseInt($definition, $raw),
            'days' => $this->parseDays($raw),
            'channels' => $this->parseChannels($raw),
            'url' => $this->parseUrl($raw),
            default => null,
        };
    }

    /**
     * A checkbox that is off posts nothing, so the form sends a hidden 0
     * first and the checkbox overrides it when ticked. Without that, turning
     * a setting off would be indistinguishable from not submitting it.
     */
    private function parseBool(mixed $raw): ?bool
    {
        return match (true) {
            in_array($raw, ['1', 1, true, 'true', 'on'], true) => true,
            in_array($raw, ['0', 0, false, 'false', 'off', ''], true) => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function parseInt(array $definition, mixed $raw): ?int
    {
        if (! is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
            return null;
        }

        $value = (int) $raw;

        if ($value < $definition['min'] || $value > $definition['max']) {
            return null;
        }

        return $value;
    }

    /**
     * An https address, or empty for none. Anything else - another scheme, a
     * relative path, a javascript: link - is refused rather than stored and
     * rendered into a public page.
     */
    private function parseUrl(mixed $raw): ?string
    {
        $url = trim((string) $raw);

        if ($url === '') {
            return '';
        }

        if (mb_strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return $url;
    }

    /**
     * @return list<int>|null
     */
    private function parseDays(mixed $raw): ?array
    {
        $parts = $this->split($raw);
        $days = [];

        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part < 1 || (int) $part > 3650) {
                return null;
            }

            $days[] = (int) $part;
        }

        if ($days === []) {
            return null;
        }

        // Furthest out first, which is the order the expiry command walks
        // them and the order a person reads them.
        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }

    /**
     * @return list<string>|null
     */
    private function parseChannels(mixed $raw): ?array
    {
        $parts = array_map('strtolower', $this->split($raw));

        if ($parts === [] || array_diff($parts, self::CHANNELS) !== []) {
            return null;
        }

        return array_values(array_unique($parts));
    }

    /**
     * @return list<string>
     */
    private function split(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $stored = [];

        foreach (DB::table('settings')->get() as $row) {
            $stored[$row->key] = json_decode($row->value, true);
        }

        return $stored;
    }
}
