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
 * it is asserted structurally: the layouts show the one flash component, and
 * it renders every key.
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

    /** The one place they are rendered. */
    private const FLASH = 'components/ui/flash.blade.php';

    /**
     * One component renders the messages for both surfaces, so neither can
     * drop a key the other shows - which is how the console once lost
     * 'warning', when each surface had a partial of its own.
     */
    public function test_the_flash_renders_every_key(): void
    {
        $source = $this->read(self::VIEWS.'/'.self::FLASH);

        foreach (self::KEYS as $key) {
            $this->assertStringContainsString(
                "session('".$key."')",
                $source,
                self::FLASH.' does not render '.$key.', so a controller flashing it shows nothing'
            );
        }
    }

    /**
     * And every layout shows it, so no page has to remember.
     */
    public function test_every_layout_shows_the_flash(): void
    {
        foreach (['portal', 'admin'] as $layout) {
            $this->assertStringContainsString(
                '<x-ui.flash',
                $this->read(self::VIEWS.'/layouts/'.$layout.'.blade.php'),
                'layouts/'.$layout.'.blade.php does not show the flash, so only the pages that'
                .' remember to show it themselves can show a message'
            );
        }
    }

    /**
     * Shown by the layouts and nowhere else, so a message is not shown twice.
     */
    public function test_the_flash_is_shown_once(): void
    {
        $shown = [];

        foreach ($this->bladeFiles(self::VIEWS) as $file) {
            $count = substr_count($this->read($file), '<x-ui.flash');

            if ($count > 0) {
                $shown[str_replace('\\', '/', substr($file, strlen(self::VIEWS) + 1))] = $count;
            }
        }

        ksort($shown);

        $this->assertSame(
            ['layouts/admin.blade.php' => 1, 'layouts/portal.blade.php' => 1],
            $shown,
            'the flash should be shown once, by the admin and portal layouts and nowhere else'
        );
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
