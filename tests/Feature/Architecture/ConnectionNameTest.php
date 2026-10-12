<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No code names a database connection that configuration chooses.
 *
 * `fatoora:verify-hash-chain --database=` rewrote
 * `database.connections.pgsql.database` and purged `pgsql`. This application
 * runs on mysql: .env.example sets DB_CONNECTION=mysql, the image installs
 * pdo_mysql and no pdo_pgsql at all, and CI runs mysql:8.4. So the option
 * reconfigured a connection nothing uses, and the command then verified the
 * default database while printing the name it had been asked for.
 *
 * The consequence is the reason this is a test and not a one-line fix. That
 * flag exists for the disaster-recovery procedure, which restores a backup
 * into a scratch database and runs this command to prove the hash chain
 * survived the restore. It was reading production instead, so the check
 * passed, and passing is what stopped anyone looking further. A restore could
 * have been silently unusable for as long as the procedure had been followed.
 *
 * Hardcoding is also wrong in the smaller way: the connection is chosen by
 * DB_CONNECTION, so any literal here is a second, quieter opinion about which
 * database this is.
 */
class ConnectionNameTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * The connection names Laravel ships in config/database.php. Any of them
     * written into application code is the same mistake.
     *
     * @var list<string>
     */
    private const NAMES = ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    public function test_no_connection_name_is_hardcoded(): void
    {
        $found = [];

        foreach ($this->sources() as $path) {
            $source = (string) file_get_contents($path);

            foreach (self::NAMES as $name) {
                foreach ([
                    'database.connections.'.$name,
                    "DB::purge('".$name."')",
                    "DB::connection('".$name."')",
                ] as $literal) {
                    if (str_contains($source, $literal)) {
                        $found[] = $this->relative($path).' names the '.$name
                            .' connection: '.$literal;
                    }
                }
            }
        }

        $this->assertSame([], $found, implode("\n", $found)."\n\n"
            .'The connection is chosen by DB_CONNECTION, so a name written here is a '
            .'second opinion about which database this is - and the quieter one wins '
            .'silently. Read config(\'database.default\') instead.');
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP));

        foreach ($it as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    private function relative(string $path): string
    {
        $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        // The iterator yields the unresolved path this file builds from
        // __DIR__, so it still carries the ../../.. that got it here.
        $at = strrpos($normalised, '/app/');

        return $at === false ? $normalised : substr($normalised, $at + 1);
    }
}
