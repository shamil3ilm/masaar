<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Support\Facades\Log;

/**
 * The service provider an organization's documents go through.
 *
 * A taxpayer appoints its own provider (Electronic Invoicing Guidelines
 * v1.1, p14; members of a tax group may each appoint a different one, p38).
 * The platform works with one partner provider now, so every organization
 * resolves to it; an organization's customization 'einvoicing.service_provider'
 * may name another provider configured under peppol.providers, by its
 * purpose key, and its documents then go through that one. No other
 * provider is configured yet, so this is the seam, not a second driver.
 *
 * Callbacks still arrive at the platform provider's routes and are verified
 * with its secret; a second provider needs routes that say which connection
 * a callback is for (docs/compliance/ae/PROVIDER-ONBOARDING.md, gap 3).
 */
final class AspGatewayResolver
{
    public const PLATFORM = 'platform';

    public const SETTING = 'einvoicing.service_provider';

    public function __construct(
        private readonly AspGateway $platform,
        private readonly Customizations $customizations,
    ) {}

    /**
     * The purpose key of the provider the organization is resolved to.
     */
    public function providerFor(?string $organizationId): string
    {
        if ($organizationId === null || $organizationId === '') {
            return self::PLATFORM;
        }

        $key = $this->customizations->get(self::SETTING, Scope::organization($organizationId));

        return is_string($key) && $key !== '' ? $key : self::PLATFORM;
    }

    public function forOrganization(?string $organizationId): AspGateway
    {
        $key = $this->providerFor($organizationId);

        if ($key === self::PLATFORM) {
            return $this->platform;
        }

        $provider = config('peppol.providers.'.$key);
        $baseUrl = is_array($provider) ? ($provider['base_url'] ?? null) : null;

        // Named but not connected: the platform's, loudly, rather than nothing.
        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::warning('An organization names a service provider that is not configured; its documents use the platform provider', [
                'org_id' => $organizationId,
                'provider' => $key,
            ]);

            return $this->platform;
        }

        return new HttpAspGateway(
            baseUrl: $baseUrl,
            clientId: $provider['client_id'] ?? null,
            clientSecret: $provider['client_secret'] ?? null,
            signature: new WebhookSignature($provider['webhook_secret'] ?? null),
        );
    }

    public function forDocument(PeppolDocument $document): AspGateway
    {
        return $this->forOrganization((string) $document->org_id);
    }

    /**
     * Configured providers, purpose key => label.
     *
     * @return array<string, string>
     */
    public static function configured(): array
    {
        $providers = [];

        foreach ((array) config('peppol.providers', [self::PLATFORM => ['label' => 'Platform partner provider']]) as $key => $provider) {
            $providers[(string) $key] = (string) ($provider['label'] ?? $key);
        }

        return $providers;
    }
}
