<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No config array may declare the same key twice.
 *
 * PHP keeps the last one and says nothing. config/fatoora.php declared
 * 'credentials' twice, and the second — a storage block — replaced the ZATCA
 * API username and password, leaving every call to the authority
 * authenticating with null. It declared 'queue' twice as well, discarding the
 * connection, tries, timeout and backoff that the submission job reads.
 *
 * Both survived review because the file is long and the duplicates were
 * hundreds of lines apart, which is exactly the shape a machine should catch.
 *
 * Keys are compared within their enclosing array rather than globally: the
 * same name at different depths, like 'timeout' under both 'queue' and 'tsa',
 * is fine and common.
 */
class ConfigKeyTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../../config';

    public function test_no_config_key_is_declared_twice(): void
    {
        $duplicates = [];

        foreach (glob(self::CONFIG.'/*.php') ?: [] as $file) {
            foreach ($this->duplicatesIn($file) as $duplicate) {
                $duplicates[] = basename($file).': '.$duplicate;
            }
        }

        $this->assertSame([], $duplicates, sprintf(
            'These keys are declared twice in the same array. PHP keeps the last '
            ."and discards the rest:\n  %s",
            implode("\n  ", $duplicates)
        ));
    }

    /**
     * Tokenised rather than matched by line, so each array is its own scope.
     *
     * Counting brackets is not enough: two sibling arrays sit at the same
     * depth, and guards.web.driver alongside guards.api.driver is correct
     * rather than a collision. Every `[` opens a distinct scope, and a key
     * belongs to the innermost one open when it appears.
     *
     * @return list<string>
     */
    private function duplicatesIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));

        $duplicates = [];
        $scopes = [[]];

        foreach ($tokens as $position => $token) {
            if ($token === '[') {
                $scopes[] = [];

                continue;
            }

            if ($token === ']') {
                if (count($scopes) > 1) {
                    array_pop($scopes);
                }

                continue;
            }

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (! $this->isFollowedByArrow($tokens, $position)) {
                continue;
            }

            $key = trim($token[1], "'\"");
            $current = array_key_last($scopes);

            if (isset($scopes[$current][$key])) {
                $duplicates[] = sprintf(
                    "'%s' at lines %d and %d",
                    $key,
                    $scopes[$current][$key],
                    $token[2]
                );
            } else {
                $scopes[$current][$key] = $token[2];
            }
        }

        return $duplicates;
    }

    /**
     * A string is a key only when `=>` follows it.
     *
     * @param  array<int, mixed>  $tokens
     */
    private function isFollowedByArrow(array $tokens, int $position): bool
    {
        for ($next = $position + 1; $next < count($tokens); $next++) {
            $token = $tokens[$next];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }
}
