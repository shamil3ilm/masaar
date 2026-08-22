<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Licensing\Enums\ApiScope;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Providers\AppServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A declared middleware alias must be attached to something.
 *
 * PlatformLicense was written, aliased here, and applied to no route — so the
 * gate deciding whether a deployment may serve at all never ran on a single
 * request. An alias registered and never used looks exactly like a guard that
 * is in force, which is what makes it worse than an absent one.
 *
 * Attachment counts either way it can be made: by alias in a route file, or by
 * class on a middleware group in bootstrap. The licence gate is on the api
 * group by class, because it answers a question about the deployment rather
 * than about who is calling.
 */
class MiddlewareAliasTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_every_alias_is_attached(): void
    {
        $wiring = $this->wiringSource();
        $unattached = [];

        foreach (AppServiceProvider::MIDDLEWARE_ALIASES as $alias => $class) {
            // 'scope' is applied as 'scope:invoice.read', so the alias is
            // matched with its optional parameters rather than exactly.
            $byAlias = preg_match('/[\'"]'.preg_quote($alias, '/').'(:[^\'"]*)?[\'"]/', $wiring) === 1;
            $byClass = str_contains($wiring, class_basename($class).'::class');

            if (! $byAlias && ! $byClass) {
                $unattached[] = $alias.' => '.class_basename($class);
            }
        }

        $this->assertSame([], $unattached, sprintf(
            'These middleware are aliased but attached to no route or group. An alias that '
            ."guards nothing reads as a guard that is in force:\n  %s",
            implode("\n  ", $unattached)
        ));
    }

    /**
     * A scope named on a route must be one a licence can actually hold.
     *
     * RequireScope asks License::hasScope(), which matches the granted values
     * exactly. A misspelt scope therefore matches nothing a licence can be
     * issued with, so the endpoint refuses every caller — fail-closed, but a
     * total and permanent outage of that route, and nothing about the failure
     * points at the spelling.
     */
    public function test_every_route_scope_exists(): void
    {
        preg_match_all('/scope:([a-z._]+)/', $this->wiringSource(), $matches);

        $unknown = [];

        foreach (array_unique($matches[1]) as $scope) {
            if (ApiScope::tryFrom($scope) === null) {
                $unknown[] = $scope;
            }
        }

        $this->assertSame([], $unknown, sprintf(
            'These routes require a scope no licence can hold, so they refuse every '
            ."caller:\n  %s",
            implode("\n  ", $unknown)
        ));
    }

    /**
     * Every scope a route requires must be grantable by some tier, or the
     * endpoint is unreachable no matter what a customer buys.
     */
    public function test_every_route_scope_is_grantable(): void
    {
        preg_match_all('/scope:([a-z._]+)/', $this->wiringSource(), $matches);

        $grantable = [];
        foreach (LicenseTier::cases() as $tier) {
            $grantable = [...$grantable, ...ApiScope::getDefaultsForTier($tier)];
        }
        $grantable = array_unique($grantable);

        $ungrantable = array_values(array_diff(array_unique($matches[1]), $grantable));

        $this->assertSame([], $ungrantable, sprintf(
            'No tier grants these scopes, so the routes requiring them cannot be '
            ."reached on any licence:\n  %s",
            implode("\n  ", $ungrantable)
        ));
    }

    /**
     * Everywhere middleware can be attached: the route files and the bootstrap
     * that defines the groups.
     */
    private function wiringSource(): string
    {
        $source = '';

        foreach ([self::ROOT.'/routes', self::ROOT.'/bootstrap'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $source .= file_get_contents($file->getPathname());
                }
            }
        }

        return $source;
    }
}
