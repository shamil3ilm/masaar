<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The deployment template names what a deployment must supply, and boots.
 *
 * docker/.env.template is what a partner copies to deploy. It named 29
 * settings and left out JWT_SECRET - no API token could be signed -
 * API_KEY_PEPPER, the credential key, the backup password, mail, the licence
 * and the metrics settings. And it set ZATCA_ENVIRONMENT=production without
 * UAE_FTA_ENVIRONMENT, which then defaulted to sandbox: the production boot
 * guard refuses that, so a deployment made exactly as instructed restart-looped.
 */
class DeploymentTemplateTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * A setting .env.example leaves empty is one the deployment must supply -
     * a secret, a host, an address - and if the application reads it, the
     * template names it too. Commented out counts: that is how an optional
     * one is offered.
     */
    public function test_the_template_names_every_supplied_setting(): void
    {
        $missing = array_values(array_diff($this->mustSupply(), $this->templateNames()));

        $this->assertSame([], $missing, "docker/.env.template does not mention:\n  ".implode("\n  ", $missing));
    }

    /**
     * Copied as instructed, the template passes the production boot guards
     * in AppServiceProvider.
     */
    public function test_the_template_boots_in_production(): void
    {
        $values = $this->templateValues();

        $this->assertSame('production', $values['APP_ENV'] ?? null);
        $this->assertSame('false', $values['APP_DEBUG'] ?? null, 'APP_DEBUG must be false in production');

        foreach (['ZATCA_ENVIRONMENT', 'UAE_FTA_ENVIRONMENT'] as $authority) {
            $this->assertArrayHasKey($authority, $values, "{$authority} unset defaults to sandbox, which production refuses");
            $this->assertNotSame('sandbox', $values[$authority], "{$authority}=sandbox is refused under APP_ENV=production");
        }

        $this->assertNotSame('false', $values['SESSION_ENCRYPT'] ?? null, 'production refuses unencrypted sessions');
    }

    /**
     * @return list<string>
     */
    private function mustSupply(): array
    {
        $empty = [];

        foreach (file(self::ROOT.'/.env.example', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(""|null)?$/', trim($line), $m)) {
                $empty[] = $m[1];
            }
        }

        return array_values(array_intersect(array_unique($empty), $this->readByTheApplication()));
    }

    /**
     * @return list<string>
     */
    private function readByTheApplication(): array
    {
        $names = [];

        foreach (['app', 'bootstrap', 'config', 'routes'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/'.$directory));

            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    preg_match_all("/env\('([A-Z][A-Z0-9_]*)'/", (string) file_get_contents($file->getPathname()), $m);
                    array_push($names, ...$m[1]);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every name the template mentions, set or commented out.
     *
     * @return list<string>
     */
    private function templateNames(): array
    {
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(self::ROOT.'/docker/.env.template'), $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The settings the template actually sets.
     *
     * @return array<string, string>
     */
    private function templateValues(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', (string) file_get_contents(self::ROOT.'/docker/.env.template'), $m, PREG_SET_ORDER);

        $values = [];
        foreach ($m as [, $name, $value]) {
            $values[$name] = trim($value);
        }

        return $values;
    }
}
