<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use App\Domains\Platform\Models\WebsiteTestimonial;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What customers said, for the home page's testimonials section.
 *
 * An admin writes each one in both languages - the quote and the name are
 * required in each, the role and company optional, the other language's
 * shown where one is left empty - and orders and publishes them. The page
 * shows only published ones, and the section only when it is turned on in
 * Admin › Website and at least one is published.
 *
 * All plain text: shown escaped, like every section's words (PlainText).
 */
class Testimonials
{
    public const LOGO_MAX_KILOBYTES = 512;

    private const QUOTE_MAX = 600;

    private const STEP = 10;

    public function __construct(
        private readonly WebsiteImages $images,
    ) {}

    /**
     * Every testimonial, in the order the page shows them.
     *
     * @return Collection<int, WebsiteTestimonial>
     */
    public function all(): Collection
    {
        return WebsiteTestimonial::query()->orderBy('position')->orderBy('created_at')->get();
    }

    /**
     * @return Collection<int, WebsiteTestimonial>
     */
    public function published(): Collection
    {
        return WebsiteTestimonial::query()->where('published', true)->orderBy('position')->orderBy('created_at')->get();
    }

    public function hasPublished(): bool
    {
        return WebsiteTestimonial::query()->where('published', true)->exists();
    }

    public function find(string $id): ?WebsiteTestimonial
    {
        return WebsiteTestimonial::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, ?UploadedFile $logo): WebsiteTestimonial
    {
        $testimonial = new WebsiteTestimonial($this->clean($input));
        $testimonial->position = (int) WebsiteTestimonial::query()->max('position') + self::STEP;
        $this->attachLogo($testimonial, $logo);
        $testimonial->save();

        return $testimonial;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(WebsiteTestimonial $testimonial, array $input, ?UploadedFile $logo, bool $removeLogo): void
    {
        $testimonial->fill($this->clean($input));

        if ($logo !== null || $removeLogo) {
            $old = $testimonial->logo_path;
            $testimonial->logo_path = null;
            $testimonial->logo_type = null;
            $this->attachLogo($testimonial, $logo);
            $testimonial->save();
            $this->images->delete($old);

            return;
        }

        $testimonial->save();
    }

    public function setPublished(WebsiteTestimonial $testimonial, bool $published): void
    {
        $testimonial->published = $published;
        $testimonial->save();
    }

    /**
     * One place earlier or later in the list.
     *
     * @param  'up'|'down'  $direction
     */
    public function move(WebsiteTestimonial $testimonial, string $direction): void
    {
        $order = $this->all()->values();
        $from = $order->search(fn (WebsiteTestimonial $each): bool => $each->id === $testimonial->id);
        $to = $direction === 'up' ? $from - 1 : $from + 1;

        if ($from === false || $to < 0 || $to >= $order->count()) {
            return;
        }

        $ids = $order->pluck('id')->all();
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

        DB::transaction(function () use ($ids, $order): void {
            foreach ($ids as $index => $id) {
                $row = $order->firstWhere('id', $id);
                $row->position = ($index + 1) * self::STEP;
                $row->isDirty() && $row->save();
            }
        });
    }

    /**
     * Removed through the model, so who removed it is kept. The logo stays
     * with the removed record.
     */
    public function delete(WebsiteTestimonial $testimonial): void
    {
        $testimonial->delete();
    }

    /**
     * A logo as served: its file and type, or null when it has none.
     *
     * @return array{path: string, type: string}|null
     */
    public function logo(WebsiteTestimonial $testimonial): ?array
    {
        return $this->images->exists($testimonial->logo_path) && $testimonial->logo_type !== null
            ? ['path' => $this->images->absolutePath((string) $testimonial->logo_path), 'type' => $testimonial->logo_type]
            : null;
    }

    private function attachLogo(WebsiteTestimonial $testimonial, ?UploadedFile $logo): void
    {
        if ($logo === null) {
            return;
        }

        $stored = $this->images->store($logo, 'testimonials', self::LOGO_MAX_KILOBYTES, 'logo');
        $testimonial->logo_path = $stored['path'];
        $testimonial->logo_type = $stored['type'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function clean(array $input): array
    {
        $text = fn (string $key): ?string => is_scalar($input[$key] ?? null) && trim((string) $input[$key]) !== ''
            ? trim(str_replace(["\r\n", "\r"], "\n", (string) $input[$key]))
            : null;

        $values = [];

        foreach (['quote', 'name', 'role', 'company'] as $field) {
            foreach (SectionCatalogue::LOCALES as $locale) {
                $values[$field.'_'.$locale] = $text($field.'_'.$locale);
            }
        }

        $labels = [
            'quote_en' => __('Quote in English'), 'quote_ar' => __('Quote in Arabic'),
            'name_en' => __('Name in English'), 'name_ar' => __('Name in Arabic'),
            'role_en' => __('Role in English'), 'role_ar' => __('Role in Arabic'),
            'company_en' => __('Company in English'), 'company_ar' => __('Company in Arabic'),
        ];

        Validator::make($values, [
            'quote_en' => ['required', 'string', 'max:'.self::QUOTE_MAX],
            'quote_ar' => ['required', 'string', 'max:'.self::QUOTE_MAX],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'role_en' => ['nullable', 'string', 'max:120'],
            'role_ar' => ['nullable', 'string', 'max:120'],
            'company_en' => ['nullable', 'string', 'max:160'],
            'company_ar' => ['nullable', 'string', 'max:160'],
        ], [], $labels)->validate();

        return [...$values, 'published' => filter_var($input['published'] ?? false, FILTER_VALIDATE_BOOLEAN)];
    }
}
