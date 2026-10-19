<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Console\PintValidator;

/**
 * The published rules' verdict, scripted, where Java and the artefacts are
 * not at hand. SimulatorConformanceTest runs the real ones.
 */
final class StubPintValidator extends PintValidator
{
    /** @var list<string> every document judged, in order */
    public array $judged = [];

    /**
     * @param  list<string>  $verdict  what failures() answers
     */
    public function __construct(private readonly array $verdict = []) {}

    public function available(): bool
    {
        return true;
    }

    public function failures(string $xml): array
    {
        $this->judged[] = $xml;

        return $this->verdict;
    }
}
