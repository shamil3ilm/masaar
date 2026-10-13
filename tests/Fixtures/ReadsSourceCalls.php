<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finding the arguments of a call, for the ratchets that read source.
 *
 * Several invariants in this suite are about how a call is written rather
 * than what it returns - an HTTP status passed where the message belongs, a
 * message buried in a data payload - and none of those are visible at
 * runtime. They need the argument list.
 *
 * A regex cannot do it: the arguments contain commas inside nested arrays,
 * closures and strings, and the calls span lines. So this matches brackets
 * and splits on the commas at depth one. It lives here because
 * ErrorEnvelopeTest and MessageStyleTest both need it, and a copy in each
 * would be the duplication these tests exist to prevent.
 */
trait ReadsSourceCalls
{
    /**
     * The top-level arguments of every call to $needle in $source.
     *
     * $needle includes the opening bracket, for example "ApiResponse::error(".
     *
     * @return list<list<string>>
     */
    protected function argumentsOf(string $source, string $needle): array
    {
        $calls = [];
        $offset = 0;

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $calls[] = $this->splitArguments($source, $start + strlen($needle));
            $offset = $start + strlen($needle);
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function splitArguments(string $source, int $from): array
    {
        $depth = 1;
        $argument = '';
        $arguments = [];
        $quote = null;

        for ($i = $from, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];

            // Inside a string literal nothing is structural. Without this a
            // bracket or comma in a message would unbalance the count.
            if ($quote !== null) {
                $argument .= $char;

                if ($char === '\\') {
                    $argument .= $source[$i + 1] ?? '';
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $argument .= $char;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($char === ',' && $depth === 1) {
                $arguments[] = $argument;
                $argument = '';

                continue;
            }

            $argument .= $char;
        }

        if (trim($argument) !== '') {
            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    protected function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
