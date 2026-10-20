{{--
    A board item's words, in both languages, for a new item or one already
    on the board: what every customer reads, with no company named.

    @param \App\Domains\Support\Models\FeatureBoardItem|null $item
    @param string|null $title     a starting title for a new item
    @param string|null $category  a starting category for a new item
    @param list<\App\Domains\Support\DTOs\RequestCategory> $categories
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <x-ui.field label="Title in English" error="title">
        <x-ui.input type="text" name="title" maxlength="160" required value="{{ old('title', $item?->title ?? ($title ?? '')) }}" />
    </x-ui.field>
    <x-ui.field label="Title in Arabic" error="title_ar" optional>
        <x-ui.input type="text" name="title_ar" maxlength="160" dir="rtl" lang="ar" value="{{ old('title_ar', $item?->title_ar) }}" />
    </x-ui.field>
    <x-ui.field label="Summary in English" error="summary">
        <x-ui.textarea name="summary" rows="4" maxlength="5000" required>{{ old('summary', $item?->summary) }}</x-ui.textarea>
    </x-ui.field>
    <x-ui.field label="Summary in Arabic" error="summary_ar" optional>
        <x-ui.textarea name="summary_ar" rows="4" maxlength="5000" dir="rtl" lang="ar">{{ old('summary_ar', $item?->summary_ar) }}</x-ui.textarea>
    </x-ui.field>
    <x-ui.field label="Category" error="category">
        <x-ui.select name="category">
            @foreach ($categories as $option)
                <option value="{{ $option->key }}" @selected(old('category', $item?->category ?? ($category ?? null)) === $option->key)>{{ $option->label }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>
</div>
