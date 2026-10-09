<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Console\Commands\FatooraGenerateCsr;
use App\Console\Commands\FatooraOnboarding;
use App\Domains\Compliance\Fatoora\Services\CsrBuilder;
use Tests\TestCase;

/**
 * One answer to the question of which ZATCA environment this is.
 *
 * Three things have to name the same environment or onboarding fails on its
 * own configuration: the template inside the certificate request, the URL the
 * request is sent to, and the URL the compliance documents are sent to. The
 * template is what tells ZATCA which environment a request is for, and a
 * request carrying one environment's template is refused by another's
 * endpoint - with an error about the certificate, not about the mismatch.
 *
 * They disagreed. fatoora:generate-csr handed back the simulation template
 * whatever was configured, fatoora:onboard defaulted to the simulation
 * endpoint, and config('fatoora.environment') - which is what
 * FatooraConfig::getBaseUrl() reads for everything after onboarding -
 * defaults to sandbox. So the scheduled sandbox round trip built a simulation
 * request and onboarded against the sandbox, and would have been refused
 * before reaching anything it exists to check.
 *
 * Both commands now read the configuration when no target is named, so there
 * is one value to set. These tests hold that: a hardcoded default on either
 * option is the defect coming back.
 */
class EnvironmentAgreementTest extends TestCase
{
    /**
     * Every environment the endpoints name has its own template, so naming
     * one of them cannot silently select another's request.
     */
    public function test_each_environment_has_its_template(): void
    {
        $endpoints = array_keys((array) config('fatoora.endpoints'));

        $this->assertNotEmpty($endpoints);

        $templates = [];

        foreach ($endpoints as $environment) {
            $templates[$environment] = CsrBuilder::templateFor($environment);
        }

        $this->assertSame(
            $templates,
            array_unique($templates),
            'Two environments share a certificate template, so a request for '
                .'one would be accepted as a request for the other: '
                .json_encode($templates)
        );
    }

    /**
     * Neither command may carry its own default environment.
     *
     * An option with a default answers the question itself, and the two
     * commands then answer it differently - which is exactly how a simulation
     * request came to be sent to the sandbox. An empty default makes the
     * configuration the single answer.
     */
    public function test_neither_command_defaults_its_target(): void
    {
        $offenders = [];

        foreach ([FatooraGenerateCsr::class, FatooraOnboarding::class] as $class) {
            $default = $this->app->make($class)
                ->getDefinition()
                ->getOption('target')
                ->getDefault();

            if ($default !== null && $default !== '') {
                $offenders[] = sprintf('%s defaults --target to "%s"', class_basename($class), $default);
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders)."\n\n"
            .'Leave --target empty and fall back to config(\'fatoora.environment\'), '
            .'so the request and the endpoint cannot name different environments.');
    }

    /**
     * And the configured environment is one the endpoints know.
     *
     * The endpoint lookup falls back to simulation for a name it does not
     * recognise, while the template falls back to sandbox - so a typo in
     * ZATCA_ENVIRONMENT sends a sandbox request to the simulation endpoint
     * rather than failing.
     */
    public function test_the_configured_environment_is_known(): void
    {
        $environment = (string) config('fatoora.environment');

        $this->assertArrayHasKey(
            $environment,
            (array) config('fatoora.endpoints'),
            "ZATCA_ENVIRONMENT is \"{$environment}\", which names no endpoint. "
                .'The endpoint would fall back to simulation and the template to '
                .'sandbox, and the request would be refused.'
        );
    }
}
