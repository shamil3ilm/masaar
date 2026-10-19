<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use App\Domains\Platform\Models\WebsiteSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How the public site appears in search results and when a link to it is
 * shared: its title and description, and the title, text and picture a
 * shared link shows - each in English and Arabic.
 *
 * Kept as one more website_sections row, under the key 'seo', with no
 * place on the page: the same bilingual text, the same checks (SectionInput:
 * both languages, plain text, no prices) and the same audit as a section,
 * without a table of its own. The picture is an image on the website disk
 * (WebsiteImages). Out of the box it says what the page always said.
 */
class WebsiteSeo
{
    public const KEY = 'seo';

    public const IMAGE_MAX_KILOBYTES = 1024;

    private const CACHE_KEY = 'website.seo';

    public function __construct(
        private readonly SectionInput $input,
        private readonly WebsiteImages $images,
    ) {}

    public function definition(): SectionDefinition
    {
        return new SectionDefinition(self::KEY, __('Search and sharing'),
            __('How the public site appears in search results and when a link to it is shared.'),
            [
                new SectionField('title', __('Page title'), limit: 70),
                new SectionField('description', __('Description'), paragraph: true, limit: 300),
                new SectionField('share_title', __('Shared link title'), limit: 90),
                new SectionField('share_text', __('Shared link text'), paragraph: true, limit: 300),
            ]);
    }

    /**
     * The shipped text, both languages: what the page's head always said.
     *
     * @return array<string, array<string, string>>
     */
    public function defaults(): array
    {
        $both = fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];

        return [
            'title' => $both(__('E-invoicing', [], 'en'), __('E-invoicing', [], 'ar')),
            'description' => $both(
                __('E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.', [], 'en'),
                __('E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.', [], 'ar'),
            ),
            'share_title' => $both(__('E-invoicing', [], 'en'), __('E-invoicing', [], 'ar')),
            'share_text' => $both(
                __('E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.', [], 'en'),
                __('E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.', [], 'ar'),
            ),
        ];
    }

    /** @return array<string, mixed> both languages, an admin's or the default */
    public function texts(): array
    {
        $texts = $this->row()['content'] ?? null;

        return is_array($texts) && isset($texts['title']) ? array_intersect_key($texts, $this->defaults()) : $this->defaults();
    }

    public function content(?string $locale = null): SectionContent
    {
        $locale ??= app()->getLocale();

        return new SectionContent(self::KEY, $this->texts(), in_array($locale, SectionCatalogue::LOCALES, true) ? $locale : 'en');
    }

    public function edited(): bool
    {
        return $this->texts() !== $this->defaults();
    }

    /**
     * The shared picture, as served, or null when there is none.
     *
     * @return array{path: string, type: string, version: int}|null
     */
    public function image(): ?array
    {
        $image = $this->row()['content']['image'] ?? null;

        return is_array($image) && $this->images->exists($image['path'] ?? null)
            ? ['path' => $this->images->absolutePath($image['path']), 'type' => (string) $image['type'], 'version' => (int) ($image['version'] ?? 0)]
            : null;
    }

    /**
     * Save the text, and replace or remove the picture.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} what was kept before and after
     */
    public function save(array $input, ?UploadedFile $image, bool $removeImage): array
    {
        $texts = $this->input->clean($this->definition(), $input);
        $stored = $image === null ? null : $this->images->store($image, 'share', self::IMAGE_MAX_KILOBYTES, 'image');
        $before = $this->row()['content'] ?? null;
        $old = $before['image'] ?? null;

        $picture = match (true) {
            $stored !== null => [...$stored, 'version' => time()],
            $removeImage => null,
            default => $old,
        };

        $content = $texts === $this->defaults() && $picture === null ? null : [...$texts, 'image' => $picture];

        DB::transaction(function () use ($content): void {
            $row = WebsiteSection::query()->lockForUpdate()->firstOrNew(['key' => self::KEY], ['enabled' => true, 'position' => 0]);
            $row->content = $content;
            $row->save();
        });

        Cache::forget(self::CACHE_KEY);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));

        if (($stored !== null || $removeImage) && is_array($old)) {
            $this->images->delete($old['path'] ?? null);
        }

        return [(array) $before, (array) $content];
    }

    /**
     * The stored row's content, or an empty array with no row: an empty
     * array, not null, so "nothing stored" is cached too.
     *
     * @return array{content?: array<string, mixed>|null}
     */
    private function row(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => WebsiteSection::query()->where('key', self::KEY)->first()?->only(['content']) ?? []);
    }
}
