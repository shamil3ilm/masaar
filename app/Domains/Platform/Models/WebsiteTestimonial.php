<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Organization\Concerns\RecordsActors;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer's words for the home page. Written by Testimonials only; the
 * logo's path and type are set there from a checked upload, never from
 * input, and its place in the list by moving it.
 *
 * The platform's own, so not tenant-scoped.
 *
 * @property string $id
 * @property string $quote_en
 * @property string $quote_ar
 * @property string $name_en
 * @property string $name_ar
 * @property string|null $role_en
 * @property string|null $role_ar
 * @property string|null $company_en
 * @property string|null $company_ar
 * @property string|null $logo_path
 * @property string|null $logo_type
 * @property bool $published
 * @property int $position
 * @property Carbon|null $updated_at
 */
class WebsiteTestimonial extends Model
{
    use HasUuids;
    use RecordsActors;
    use SoftDeletes;

    protected $fillable = [
        'quote_en',
        'quote_ar',
        'name_en',
        'name_ar',
        'role_en',
        'role_ar',
        'company_en',
        'company_ar',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** A text in the page's language, the other one where it was left empty. */
    public function say(string $field, string $locale): string
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

        return (string) ($this->getAttribute($field.'_'.$locale) ?: $this->getAttribute($field.'_'.$other));
    }
}
