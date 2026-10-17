<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use LogicException;

/**
 * The jurisdictions this deployment knows, by country code.
 *
 * Each module registers itself from its own service provider, so adding a
 * country touches its own folder and one provider line, and nothing here.
 *
 * A registration is a factory, not an instance: the module is built when it
 * is asked for, each time. Registering instances would build every country's
 * services at the first resolve and keep them for the life of the process,
 * so a collaborator swapped later - a test's mock, a worker's configuration -
 * would never reach the module that holds it.
 */
#[Singleton]
class JurisdictionRegistry
{
    /** @var array<string, Closure(): Jurisdiction> */
    private array $factories = [];

    private ?string $default = null;

    /**
     * @param  Closure(): Jurisdiction  $factory
     * @param  bool  $default  the module for an organization with no country
     */
    public function register(string $code, Closure $factory, bool $default = false): void
    {
        if (isset($this->factories[$code])) {
            throw new LogicException("Jurisdiction {$code} is registered twice.");
        }

        if ($default && $this->default !== null) {
            throw new LogicException("Jurisdiction {$code} cannot be the default: {$this->default} already is.");
        }

        $this->factories[$code] = $factory;

        if ($default) {
            $this->default = $code;
        }
    }

    /**
     * @throws JurisdictionUnavailableException when no module is registered for the code
     */
    public function get(string $code): Jurisdiction
    {
        $factory = $this->factories[$code] ?? throw JurisdictionUnavailableException::unknown($code);
        $jurisdiction = $factory();

        if ($jurisdiction->code() !== $code) {
            throw new LogicException("Jurisdiction registered as {$code} answers as {$jurisdiction->code()}.");
        }

        return $jurisdiction;
    }

    /**
     * The module for the code, switched on or not; the default module for a
     * blank code or one no module is registered for. For rules a form or a
     * request follows, which apply whether or not the country issues now.
     *
     * @throws JurisdictionUnavailableException when no module is the default
     */
    public function orDefault(?string $code): Jurisdiction
    {
        return $this->get($code !== null && isset($this->factories[$code]) ? $code : $this->defaultCode());
    }

    /**
     * @throws JurisdictionUnavailableException when no module is the default
     */
    public function defaultCode(): string
    {
        return $this->default ?? throw JurisdictionUnavailableException::noDefault();
    }

    /**
     * @return list<string> every registered code, sorted
     */
    public function registered(): array
    {
        $codes = array_keys($this->factories);
        sort($codes);

        return $codes;
    }

    /**
     * @return list<string> the registered codes this deployment issues in, sorted
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->registered(),
            fn (string $code): bool => $this->get($code)->enabled(),
        ));
    }
}
