<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Pilot;

/**
 * One line of a pilot readiness report: what was checked, whether it held,
 * what was seen, and - when it did not - what to do about it.
 *
 * The key is stable, for a caller that looks one up; the name and the fix
 * are for the operator reading the table.
 */
final readonly class Check
{
    public function __construct(
        public string $key,
        public string $name,
        public bool $passed,
        public string $detail = '',
        public string $fix = '',
    ) {}

    public static function pass(string $key, string $name, string $detail = ''): self
    {
        return new self($key, $name, true, $detail);
    }

    public static function fail(string $key, string $name, string $detail, string $fix = ''): self
    {
        return new self($key, $name, false, $detail, $fix);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public function row(): array
    {
        return [$this->name, $this->passed ? 'PASS' : 'FAIL', $this->detail, $this->fix];
    }
}
