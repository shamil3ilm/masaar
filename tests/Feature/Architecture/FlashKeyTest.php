<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A message a controller flashes is a message some page renders.
 *
 * The portal partial was included by hand in two of eleven portal views, so
 * nine pages could not show a flash at all. PortalInvoiceDraftController
 * flashes a long, carefully worded 'error' and redirects back() to the create
 * form - which was not one of the two. A taxpayer whose draft failed to save
 * got the form back, repopulated, with no indication that anything had gone
 * wrong, and that message had never once rendered.
 *
 * Two more of the same kind: the admin partial rendered 'success' and 'error'
 * but not 'warning', which the portal partial renders and which is already
 * the word this codebase uses for "it worked, now read this"; and
 * auth/login.blade.php has rendered session('status') in a blue band since it
 * was written while nothing anywhere set it, so the signed-out confirmation
 * it was built for never appeared.
 *
 * None of this is catchable by a feature test on one page, because the defect
 * is the gap between where messages are written and where they are read. So
 * it is asserted structurally: the layouts include the partial, and the
 * partials agree on the keys.
 */
class FlashKeyTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    private const APP = __DIR__.'/../../../app';

    /**
     * The keys a flash partial is expected to render.
     *
     * api_secret is deliberately absent: it is shown once by its own partial,
     * admin/licences/partials/secret-once.blade.php, because it is a
     * credential and not a notification.
     *
     * @var list<string>
     */
    private const KEYS = ['success', 'error', 'warning'];

    /**
     * Both surfaces render the same keys, so neither drops one silently.
     */
    public function test_both_partials_render_the_same_keys(): void
    {
        foreach (['portal/partials/flash.blade.php', 'admin/partials/flash.blade.php'] as $partial) {
            $source = $this->read(self::VIEWS.'/'.$partial);

            foreach (self::KEYS as $key) {
                $this->assertStringContainsString(
                    "session('".$key."')",
                    $source,
                    $partial.' does not render '.$key.', so a controller flashing it shows nothing'
                );
            }
        }
    }

    /**
     * And every layout includes one, so no page has to remember.
     */
    public function test_every_layout_includes_the_partial(): void
    {
        foreach (['portal' => 'portal.partials.flash', 'admin' => 'admin.partials.flash'] as $layout => $partial) {
            $this->assertStringContainsString(
                "@include('".$partial."')",
                $this->read(self::VIEWS.'/layouts/'.$layout.'.blade.php'),
                'layouts/'.$layout.'.blade.php does not include its flash partial, so only the pages that'
                .' remember to include it themselves can show a message'
            );
        }
    }

    /**
     * Included once, so a message is not shown twice.
     */
    public function test_the_partial_is_included_once(): void
    {
        foreach (['portal', 'admin'] as $surface) {
            $includes = [];

            foreach ($this->bladeFiles(self::VIEWS) as $file) {
                $count = substr_count($this->read($file), "@include('".$surface.".partials.flash')");

                if ($count > 0) {
                    $includes[] = str_replace('\\', '/', substr($file, strlen(self::VIEWS) + 1));
                }
            }

            $this->assertSame(
                ['layouts/'.$surface.'.blade.php'],
                $includes,
                'the '.$surface.' flash partial should be included by its layout and nowhere else'
            );
        }
    }

    /**
     * Any key a view reads has something that writes it.
     *
     * The reverse of the orphan above, and the reason the login page had a
     * blue band nobody ever saw. Only the keys the partials do not own are
     * checked, since those are the ad hoc ones that go stale.
     */
    public function test_every_key_read_is_written(): void
    {
        $read = [];

        foreach ($this->bladeFiles(self::VIEWS) as $file) {
            preg_match_all("/session\('([a-z_]+)'\)/", $this->read($file), $matches);
            $read = array_merge($read, $matches[1]);
        }

        $unwritten = [];

        foreach (array_unique($read) as $key) {
            if (in_array($key, self::KEYS, true)) {
                continue;
            }

            if ($this->isFlashedAnywhere($key)) {
                continue;
            }

            $unwritten[] = $key;
        }

        sort($unwritten);

        $this->assertSame([], $unwritten, 'these keys are rendered but nothing ever sets them: '
            .implode(', ', $unwritten));
    }

    private function isFlashedAnywhere(string $key): bool
    {
        foreach ($this->phpFiles(self::APP) as $file) {
            if (str_contains($this->read($file), "with('".$key."'")) {
                return true;
            }
        }

        return false;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, $path.' is unreadable');

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $directory): array
    {
        return $this->filesWithExtension($directory, 'php', '.blade.php');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        return $this->filesWithExtension($directory, 'php', '.php');
    }

    /**
     * @return list<string>
     */
    private function filesWithExtension(string $directory, string $extension, string $suffix): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
