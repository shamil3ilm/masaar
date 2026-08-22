<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

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
