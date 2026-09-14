<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps console commands on the container's copies of domain services.
 *
 * A command that builds signers, hashers and clients with `new` wires its own
 * object graph, which drifts from the one the application uses: fatoora:onboard
 * assembled a second signing pipeline that disagreed with DocumentBuilder about
 * when the QR is hashed. Commands take domain services through handle() or the
 * constructor.
 *
 * The list below is a ratchet: the assertion is equality, so a command that
 * starts constructing domain services fails the build until it is listed, and
 * one that stops fails until it is delisted.
 */
class CommandConstructionTest extends TestCase
{
    private const COMMANDS = __DIR__.'/../../../app/Console/Commands';

    /**
     * Commands that still construct domain collaborators with new.
     */
    private const DECLARED = [
        'FatooraOnboarding.php',
        'FatooraValidate.php',
    ];

    public function test_commands_do_not_construct_domain_services(): void
    {
        $found = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::COMMANDS));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all('/^use App\\\\Domains\\\\[\w\\\\]+\\\\(?:Services|Client)\\\\(\w+)(?: as (\w+))?;/m', $source, $imports, PREG_SET_ORDER);

            foreach ($imports as $import) {
                $name = ($import[2] ?? '') !== '' ? $import[2] : $import[1];

                if (preg_match('/\bnew\s+'.preg_quote($name, '/').'\b/', $source) === 1) {
                    $found[] = $file->getFilename();

                    break;
                }
            }
        }

        sort($found);

        $added = array_values(array_diff($found, self::DECLARED));
        $removed = array_values(array_diff(self::DECLARED, $found));

        $this->assertSame(self::DECLARED, $found, "Commands constructing domain services changed.\n"
            .($added === [] ? '' : "Now constructing (inject instead): \n  ".implode("\n  ", $added)."\n")
            .($removed === [] ? '' : "No longer constructing (delist): \n  ".implode("\n  ", $removed)."\n"));
    }
}
