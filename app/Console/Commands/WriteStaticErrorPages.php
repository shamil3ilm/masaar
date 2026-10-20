<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Responses\ApiResponse;
use Illuminate\Console\Command;

/**
 * Write the pages nginx serves when PHP cannot answer: public/50x.html for
 * a visitor, public/50x.json for an API client.
 *
 * When php-fpm is down, restarting or too slow, nothing of the application
 * runs, so nothing can read config/brand.php for the product's name. The
 * name is put in here instead, from the brand as it stands, and the files
 * are committed - and written again where the image is built and where the
 * container starts, so a deployment that renames the product serves its own
 * name (StaticErrorPageTest fails when the committed files differ).
 *
 * The page needs nothing it could not have: no script, no inline style (the
 * content policy allows neither), nothing from another origin, and a
 * stylesheet of its own in public/errors, outside the build.
 */
class WriteStaticErrorPages extends Command
{
    protected $signature = 'platform:static-errors';

    protected $description = 'Write the pages nginx serves when the application cannot answer';

    /** The page's own stylesheet, under public/. */
    public const STYLESHEET = 'errors/static.css';

    public function handle(): int
    {
        foreach ($this->pages() as $path => $contents) {
            file_put_contents(public_path($path), $contents);
            $this->info("Wrote public/{$path}.");
        }

        return self::SUCCESS;
    }

    /**
     * What each file holds, by its path under public/.
     *
     * @return array<string, string>
     */
    public function pages(): array
    {
        // Static files are cached for a month (default.conf), so the
        // stylesheet's address changes when it does.
        $version = substr(hash_file('sha256', public_path(self::STYLESHEET)) ?: '', 0, 12);

        $html = view('errors.static.50x', ['stylesheet' => '/'.self::STYLESHEET.'?v='.$version])->render();

        $json = ApiResponse::error('The service is temporarily unavailable. Try again shortly.', 503)
            ->getContent();

        return [
            '50x.html' => str_replace("\r\n", "\n", $html),
            '50x.json' => $json."\n",
        ];
    }
}
