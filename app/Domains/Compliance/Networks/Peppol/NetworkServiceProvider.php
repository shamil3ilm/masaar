<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use Illuminate\Support\ServiceProvider;

/**
 * Peppol: which service provider the platform talks to, for every country
 * that sends through the network. Each such country registers its own
 * module, from its own ModuleServiceProvider.
 *
 * The transport is decided by configuration alone. No base URL is a
 * deployment without a provider, where documents wait for a manual upload;
 * production refuses a base URL without its credentials (AppServiceProvider),
 * so the HTTP gateway is never built half-configured there.
 *
 * Bound per resolution rather than once, so the answer follows the
 * configuration a worker or test is running with.
 */
class NetworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AspGateway::class, function (): AspGateway {
            $baseUrl = config('peppol.asp.base_url');

            if (! is_string($baseUrl) || $baseUrl === '') {
                return new NullAspGateway;
            }

            return new HttpAspGateway(
                baseUrl: $baseUrl,
                clientId: config('peppol.asp.client_id'),
                clientSecret: config('peppol.asp.client_secret'),
                signature: new WebhookSignature(config('peppol.asp.webhook_secret')),
            );
        });
    }
}
