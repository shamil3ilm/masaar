<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The PHP version is declared in three places and they have to agree.
 *
 * composer.json requires ^8.4. CI installs 8.4. The production image was
 * built on php:8.2-fpm-alpine, and had been for as long as the Dockerfile
 * existed.
 *
 * Nothing caught it, and the reasons are worth keeping:
 *
 *   The composer stage installs with --ignore-platform-reqs, which is correct
 *   for a stage that only downloads packages, and it means a version
 *   mismatch cannot fail the build there.
 *
 *   `docker build` succeeded, so the image job was green. Trivy scanned the
 *   image and found nothing, because an image running the wrong PHP is not a
 *   vulnerability. The container would have started, because php-fpm starts
 *   fine.
 *
 *   The failure arrives on the first request that reaches PHP, as a fatal
 *   from vendor/composer/platform_check.php. No CI job runs the image, so the
 *   first execution of this application inside its own container would have
 *   been in a deployment.
 *
 * The Dockerfile now runs platform_check.php during the build, which fails it
 * at 255. This test is the cheaper half: it reads the three files and compares
 * them, so the mismatch is a red test rather than a failed build.
 */
class DockerPhpVersionTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    public function test_the_image_satisfies_composer(): void
    {
        [$major, $minor] = $this->required();
        [$imageMajor, $imageMinor] = $this->imageVersion();

        $this->assertSame($major, $imageMajor,
            'The production image is PHP '.$imageMajor.'.'.$imageMinor.' and composer.json '
            .'requires ^'.$major.'.'.$minor.'. The build cannot fail on this, because the '
            .'composer stage installs with --ignore-platform-reqs - the first request to '
            .'the container does.');

        $this->assertGreaterThanOrEqual($minor, $imageMinor,
            'The production image is PHP '.$imageMajor.'.'.$imageMinor.', below the '
            .$major.'.'.$minor.' composer.json requires.');
    }

    /**
     * And CI tests on the version the image runs.
     *
     * Testing on one minor and shipping another is how a deprecation or a
     * behaviour change reaches a deployment unexercised.
     */
    public function test_ci_uses_the_image_version(): void
    {
        $workflow = (string) file_get_contents(self::ROOT.'/.github/workflows/ci.yml');

        $this->assertSame(1, preg_match("/PHP_VERSION:\s*'([0-9]+\.[0-9]+)'/", $workflow, $m),
            'ci.yml declares no PHP_VERSION.');

        [$imageMajor, $imageMinor] = $this->imageVersion();

        $this->assertSame($imageMajor.'.'.$imageMinor, $m[1],
            'CI tests on PHP '.$m[1].' and the image runs '.$imageMajor.'.'.$imageMinor.'.');
    }

    /**
     * The major and minor composer.json asks for.
     *
     * @return array{0: int, 1: int}
     */
    private function required(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT.'/composer.json'), true);

        $this->assertIsArray($composer);

        $constraint = $composer['require']['php'] ?? '';

        $this->assertSame(1, preg_match('/(\d+)\.(\d+)/', (string) $constraint, $m),
            'composer.json declares no PHP constraint this test can read: '.(string) $constraint);

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * The major and minor of the production stage's base image.
     *
     * Only the stage named production is read. The earlier stages run
     * composer and npm and are free to be anything.
     *
     * @return array{0: int, 1: int}
     */
    private function imageVersion(): array
    {
        $dockerfile = (string) file_get_contents(self::ROOT.'/Dockerfile');

        $this->assertSame(1, preg_match('/^FROM php:(\d+)\.(\d+)[^\s]*\s+AS production/m', $dockerfile, $m),
            'The Dockerfile has no `FROM php:<version> AS production` line.');

        return [(int) $m[1], (int) $m[2]];
    }
}
