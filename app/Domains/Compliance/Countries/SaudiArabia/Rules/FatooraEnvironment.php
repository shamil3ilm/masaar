<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Organization\Models\Organization;

/**
 * Which of ZATCA's three environments an organization works in.
 *
 * The developer portal (sandbox), simulation and production are separate
 * gateways, each onboarding a device with its own OTP and issuing its own
 * CSIDs (E-Invoicing Detailed Guidelines: onboarding; the Fatoora portal's
 * Simulation environment for the pre-production run). It was one switch for
 * the whole deployment, fatoora.environment, so moving one pilot
 * organization to simulation moved every organization, and their
 * developer-portal certificates went with them.
 *
 * Now each organization has one, set by a platform admin
 * (EnvironmentSwitch), defaulting to fatoora.environment. It chooses the
 * gateway its calls go to, and every credential is stored with the
 * environment that issued it (CredentialStore), so a CSID from one
 * environment is never used in another.
 */
final class FatooraEnvironment
{
    public const SANDBOX = 'sandbox';

    public const SIMULATION = 'simulation';

    public const PRODUCTION = 'production';

    public const ALL = [self::SANDBOX, self::SIMULATION, self::PRODUCTION];

    /** Where compliance_profile keeps an organization's own choice. */
    public const PROFILE_KEY = 'zatca_environment';

    /** The deployment's default, for an organization that has not been given one. */
    public static function default(): string
    {
        return (string) config('fatoora.environment', self::SANDBOX);
    }

    public static function of(Organization|string|null $organization): string
    {
        if ($organization === null) {
            return self::default();
        }

        $profile = $organization instanceof Organization
            ? $organization->compliance_profile
            : Organization::query()->whereKey($organization)->first()?->compliance_profile;

        $chosen = $profile[self::PROFILE_KEY] ?? null;

        return in_array($chosen, self::ALL, true) ? $chosen : self::default();
    }

    /** Whether the organization has an environment of its own rather than the default. */
    public static function chosen(Organization $organization): bool
    {
        return in_array($organization->compliance_profile[self::PROFILE_KEY] ?? null, self::ALL, true);
    }

    /**
     * The gateway for an environment (fatoora.endpoints).
     *
     * @throws \RuntimeException for an environment with no endpoint
     */
    public static function baseUrl(?string $environment = null): string
    {
        $environment ??= self::default();
        $url = config("fatoora.endpoints.{$environment}");

        if (! is_string($url) || $url === '') {
            throw new \RuntimeException("ZATCA environment '{$environment}' has no endpoint in config/fatoora.php.");
        }

        return $url;
    }

    /** The environment's name, as a person reads it. */
    public static function label(string $environment): string
    {
        return match ($environment) {
            self::SANDBOX => __('Sandbox'),
            self::SIMULATION => __('Simulation'),
            self::PRODUCTION => __('Production'),
            default => $environment,
        };
    }

    /** The authority's two test environments file nothing that is a tax record. */
    public static function live(string $environment): bool
    {
        return ! in_array($environment, [self::SANDBOX, self::SIMULATION], true);
    }
}
