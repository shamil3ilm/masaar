<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every CI workflow parses.
 *
 * GitHub answers a workflow it cannot parse with a failed run and no jobs at
 * all - no step, no log, no annotation saying which line. One did: an escaped
 * newline in a shell command became a literal one in the YAML, and the only
 * signal was a red run with nothing in it. Had it merged, every check on main
 * would have stopped at once. Parsing here puts the line number in the suite.
 */
class WorkflowSyntaxTest extends TestCase
{
    public function test_every_workflow_parses(): void
    {
        $workflows = glob(__DIR__.'/../../../.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [];

        $this->assertNotSame([], $workflows, 'No workflows found.');

        foreach ($workflows as $workflow) {
            try {
                $parsed = Yaml::parseFile($workflow);
            } catch (ParseException $e) {
                $this->fail(basename($workflow).' does not parse: '.$e->getMessage());
            }

            $this->assertArrayHasKey('jobs', (array) $parsed, basename($workflow).' declares no jobs.');
        }
    }
}
