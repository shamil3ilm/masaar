<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use Illuminate\Validation\ValidationException;

/**
 * A section's edit form, checked and made into the text it stores.
 *
 * Every field is required in both languages - the page is bilingual, and a
 * heading shows its other language beside it - and kept to its length. A
 * line is one line; a paragraph keeps its line breaks. Nothing may name a
 * price (PriceCheck): the website publishes none.
 *
 * A list's empty rows are the form's spare slots and are dropped; a row with
 * anything in it must be complete. A fixed list keeps its count.
 *
 * Errors are keyed as the form names its inputs (title.en, items.0.title.ar),
 * so each shows under its own field.
 */
final class SectionInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> every field in both languages
     *
     * @throws ValidationException
     */
    public function clean(SectionDefinition $definition, array $input): array
    {
        $content = [];
        $errors = [];

        foreach ($definition->fields as $field) {
            $content[$field->name] = $this->both($field, $input[$field->name] ?? null, $field->name, $errors);
        }

        foreach ($definition->lists as $list) {
            $content[$list->name] = $this->items($list, $input[$list->name] ?? [], $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<array<string, array<string, string>>>
     */
    private function items(SectionList $list, mixed $rows, array &$errors): array
    {
        $items = [];

        foreach (array_values(is_array($rows) ? $rows : []) as $row) {
            $row = is_array($row) ? $row : [];

            if ($this->blank($list, $row)) {
                continue;
            }

            $index = count($items);
            $item = [];

            foreach ($list->fields as $field) {
                $item[$field->name] = $this->both($field, $row[$field->name] ?? null, $list->name.'.'.$index.'.'.$field->name, $errors);
            }

            $items[] = $item;
        }

        $count = count($items);

        if ($list->fixed && $count !== $list->max) {
            $errors[$list->name] = __(':list keeps exactly :count items.', ['list' => $list->label, 'count' => $list->max]);
        } elseif ($count < $list->min || $count > $list->max) {
            $errors[$list->name] = __(':list needs from :min to :max items.', ['list' => $list->label, 'min' => $list->min, 'max' => $list->max]);
        }

        return $items;
    }

    /**
     * One field in both languages.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function both(SectionField $field, mixed $value, string $path, array &$errors): array
    {
        $both = [];

        foreach (SectionCatalogue::LOCALES as $locale) {
            $text = $this->normalise($field, is_array($value) ? ($value[$locale] ?? '') : '');
            $label = ['field' => $field->label, 'language' => $locale === 'ar' ? __('Arabic') : __('English')];

            if ($text === '') {
                $errors[$path.'.'.$locale] = __(':field in :language is required.', $label);
            } elseif (mb_strlen($text) > $field->max()) {
                $errors[$path.'.'.$locale] = __(':field in :language must be at most :max characters.', [...$label, 'max' => $field->max()]);
            } elseif (PriceCheck::namesPrice($text)) {
                $errors[$path.'.'.$locale] = __(':field in :language names a price. The website publishes no prices.', $label);
            }

            $both[$locale] = $text;
        }

        return $both;
    }

    /**
     * Trimmed, with Windows line ends made plain, and a line kept to one.
     */
    private function normalise(SectionField $field, mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim(is_scalar($value) ? (string) $value : ''));

        // No control characters but the line break a paragraph keeps. Format
        // characters (the joiners and direction marks Arabic uses) stay.
        $text = (string) preg_replace('/[^\P{Cc}\n]/u', '', $text);

        return $field->paragraph ? (string) preg_replace("/\n{3,}/", "\n\n", $text) : trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param  array<string, mixed>  $row */
    private function blank(SectionList $list, array $row): bool
    {
        foreach ($list->fields as $field) {
            foreach (SectionCatalogue::LOCALES as $locale) {
                $value = $row[$field->name][$locale] ?? '';

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return false;
                }
            }
        }

        return true;
    }
}
