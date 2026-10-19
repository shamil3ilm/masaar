{{--
    One full set of specimens for the style guide (admin/design-system).

    $scope  a prefix for every id, since the set is drawn four times
    $rtl    true for the right-to-left previews, whose sample text is Arabic

    The class names below are written out in full, never assembled, because
    Tailwind finds the classes to build by reading the source.
--}}
@php
    $t = fn (string $en, string $ar): string => $rtl ? $ar : $en;

    $roles = [
        ['Ground', 'ground', 'bg-ground'],
        ['Surface', 'surface', 'bg-surface'],
        ['Sunken', 'sunken', 'bg-sunken'],
        ['Line', 'line', 'bg-line'],
        ['Line, strong', 'line-strong', 'bg-line-strong'],
        ['Text', 'fg', 'bg-fg'],
        ['Muted text', 'muted', 'bg-muted'],
        ['Subtle text', 'subtle', 'bg-subtle'],
        ['Accent', 'accent', 'bg-accent'],
        ['Accent, soft', 'accent-soft', 'bg-accent-soft'],
        ['Action', 'action', 'bg-action'],
        ['Highlight', 'highlight', 'bg-highlight'],
        ['Focus', 'focus', 'bg-focus'],
        ['Success', 'success', 'bg-success'],
        ['Warning', 'warning', 'bg-warning'],
        ['Danger', 'danger', 'bg-danger'],
        ['Info', 'info', 'bg-info'],
        ['Success, soft', 'success-soft', 'bg-success-soft'],
        ['Warning, soft', 'warning-soft', 'bg-warning-soft'],
        ['Danger, soft', 'danger-soft', 'bg-danger-soft'],
        ['Info, soft', 'info-soft', 'bg-info-soft'],
    ];

    $ramps = [
        ['ink-950', 'bg-ink-950'], ['ink-900', 'bg-ink-900'], ['ink-800', 'bg-ink-800'], ['ink-700', 'bg-ink-700'],
        ['teal-700', 'bg-teal-700'], ['teal-500', 'bg-teal-500'], ['teal-300', 'bg-teal-300'], ['teal-50', 'bg-teal-50'],
        ['sand-200', 'bg-sand-200'], ['sand-100', 'bg-sand-100'],
    ];

    $type = [
        ['text-display', 'text-display font-semibold', $t('Every invoice, on the right path.', 'كل فاتورة في مسارها الصحيح.')],
        ['text-title', 'text-title font-semibold', $t('One account. The rules of each country.', 'حساب واحد. وقواعد كل دولة.')],
        ['text-heading', 'text-heading font-semibold', $t('Your company', 'شركتك')],
        ['text-lead', 'text-lead text-muted', $t('Masaar signs, files and keeps each invoice.', 'يوقّع مسار كل فاتورة ويقدّمها ويحفظها.')],
        ['text-base', 'text-base', $t('Running text, for anything longer than a line.', 'نص متصل لكل ما يزيد على سطر.')],
        ['text-sm', 'text-sm text-muted', $t('Secondary text: descriptions, table cells.', 'نص ثانوي: الأوصاف وخلايا الجداول.')],
        ['text-eyebrow', 'text-eyebrow font-semibold uppercase text-accent', $t('Where we work', 'أين نعمل')],
        ['font-mono', 'font-mono text-sm tabular-nums', 'INV-2026-00418 · 1,150.00'],
    ];

    $radii = [['control', 'rounded-control'], ['button', 'rounded-button'], ['tile', 'rounded-tile'], ['card', 'rounded-card'], ['panel', 'rounded-panel']];

    $shadows = [['card', 'shadow-card'], ['raised', 'shadow-raised'], ['invoice', 'shadow-invoice']];

    $states = ['cleared', 'reported', 'pending', 'queued', 'failed', 'rejected', 'draft', 'expired'];

    $sample = new \Illuminate\Pagination\LengthAwarePaginator(range(1, 10), 240, 10, 3, ['path' => url()->current()]);
@endphp

<div class="space-y-12">
    {{-- Colour --}}
    <section aria-labelledby="{{ $scope }}-colour" class="space-y-4">
        <h2 id="{{ $scope }}-colour" class="text-heading font-semibold">{{ $t('Colour roles', 'أدوار الألوان') }}</h2>
        <p class="max-w-3xl text-sm text-muted">{{ $t('What a screen asks for, never a hex value. Each role is redefined after dark, and the brand roles follow a theme’s colours.', 'ما تطلبه الشاشة، لا قيمة لونية. يُعاد تعريف كل دور في الوضع الداكن، وتتبع أدوار العلامة ألوان السمة.') }}</p>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($roles as [$name, $token, $class])
                <li class="overflow-hidden rounded-tile border border-line bg-surface">
                    <span class="block h-14 border-b border-line {{ $class }}"></span>
                    <span class="block px-3 py-2">
                        <span class="block text-sm font-medium">{{ $name }}</span>
                        <code class="block font-mono text-xs text-subtle" dir="ltr">{{ $token }}</code>
                    </span>
                </li>
            @endforeach
        </ul>
        <h3 class="pt-2 text-sm font-semibold text-subtle">{{ $t('Brand ramps, from the logo', 'تدرجات العلامة من الشعار') }}</h3>
        <ul class="flex flex-wrap gap-2">
            @foreach ($ramps as [$token, $class])
                <li class="flex items-center gap-2 rounded-full border border-line bg-surface py-1 pe-3 ps-1 text-xs">
                    <span class="size-6 rounded-full border border-line {{ $class }}"></span>
                    <code class="font-mono" dir="ltr">{{ $token }}</code>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Type --}}
    <section aria-labelledby="{{ $scope }}-type" class="space-y-4">
        <h2 id="{{ $scope }}-type" class="text-heading font-semibold">{{ $t('Type', 'الخطوط') }}</h2>
        <p class="max-w-3xl text-sm text-muted">{{ $t('Inter for English and IBM Plex Sans Arabic for Arabic, at 400, 500 and 600. Arabic is never letter-spaced.', 'Inter للإنجليزية وIBM Plex Sans Arabic للعربية، بأوزان 400 و500 و600. لا تُباعد حروف العربية.') }}</p>
        <div class="divide-y divide-line rounded-card border border-line bg-surface">
            @foreach ($type as [$token, $class, $text])
                <div class="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-baseline sm:gap-6">
                    <code class="w-32 shrink-0 font-mono text-xs text-subtle" dir="ltr">{{ $token }}</code>
                    <p class="min-w-0 {{ $class }}">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Shape --}}
    <section aria-labelledby="{{ $scope }}-shape" class="space-y-4">
        <h2 id="{{ $scope }}-shape" class="text-heading font-semibold">{{ $t('Radius, elevation and size', 'الزوايا والارتفاع والحجم') }}</h2>
        <div class="grid gap-6 lg:grid-cols-3">
            <ul class="flex flex-wrap gap-3">
                @foreach ($radii as [$name, $class])
                    <li class="flex size-20 items-end border border-line-strong bg-sunken p-2 {{ $class }}">
                        <code class="font-mono text-xs" dir="ltr">{{ $name }}</code>
                    </li>
                @endforeach
            </ul>
            <ul class="flex flex-wrap gap-4">
                @foreach ($shadows as [$name, $class])
                    <li class="flex size-24 items-end rounded-tile bg-surface p-2 {{ $class }}">
                        <code class="font-mono text-xs" dir="ltr">{{ $name }}</code>
                    </li>
                @endforeach
            </ul>
            <div class="space-y-2 text-sm text-muted">
                <p class="flex items-center gap-3"><span class="inline-block h-11 w-3 rounded bg-accent"></span> {{ $t('44px: every control and action', '44 بكسل: كل عنصر تحكم وإجراء') }}</p>
                <p class="flex items-center gap-3"><span class="inline-block h-12 w-3 rounded bg-action"></span> {{ $t('48px: the one call to action', '48 بكسل: الدعوة الرئيسية') }}</p>
            </div>
        </div>
    </section>

    {{-- Actions --}}
    <section aria-labelledby="{{ $scope }}-actions" class="space-y-4">
        <h2 id="{{ $scope }}-actions" class="text-heading font-semibold">{{ $t('Actions', 'الإجراءات') }}</h2>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="button">{{ $t('Issue invoice', 'إصدار الفاتورة') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary">{{ $t('Filter', 'تصفية') }}</x-ui.button>
            <x-ui.button type="button" variant="ghost">{{ $t('Cancel', 'إلغاء') }}</x-ui.button>
            <x-ui.button type="button" variant="danger">{{ $t('Revoke', 'إلغاء الترخيص') }}</x-ui.button>
            <x-ui.button type="button" disabled>{{ $t('Disabled', 'معطّل') }}</x-ui.button>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="button" size="sm">{{ $t('Small', 'صغير') }}</x-ui.button>
            <x-ui.button type="button">{{ $t('Medium', 'متوسط') }}</x-ui.button>
            <x-ui.button type="button" size="lg">{{ $t('Start your free month', 'ابدأ شهرك المجاني') }}</x-ui.button>
            <x-ui.button href="#" variant="secondary">{{ $t('A link, drawn as a button', 'رابط بشكل زر') }}</x-ui.button>
            <x-ui.link-button type="button">{{ $t('Sign out', 'تسجيل الخروج') }}</x-ui.link-button>
            <x-ui.link-button type="button" tone="muted">{{ $t('Muted link button', 'زر رابط هادئ') }}</x-ui.link-button>
        </div>
    </section>

    {{-- Forms --}}
    <section aria-labelledby="{{ $scope }}-forms" class="space-y-4">
        <h2 id="{{ $scope }}-forms" class="text-heading font-semibold">{{ $t('Form controls', 'عناصر النماذج') }}</h2>
        <div class="grid gap-5 rounded-card border border-line bg-surface p-5 sm:grid-cols-2">
            <x-ui.field :label="$t('Legal name', 'الاسم القانوني')" :hint="$t('As on the commercial registration.', 'كما في السجل التجاري.')">
                <x-ui.input name="specimen_name" value="Acme Trading Co." />
            </x-ui.field>
            <x-ui.field :label="$t('Building number', 'رقم المبنى')" optional>
                <x-ui.input name="specimen_building" inputmode="numeric" placeholder="1234" />
            </x-ui.field>
            <label class="block text-sm font-medium text-fg">
                {{ $t('VAT number (invalid)', 'الرقم الضريبي (غير صالح)') }}
                <x-ui.input name="specimen_vat" value="12345" aria-invalid="true" />
                <span class="mt-1 block text-xs font-medium text-danger">{{ $t('A VAT number is 15 digits.', 'الرقم الضريبي 15 رقماً.') }}</span>
            </label>
            <x-ui.field :label="$t('Country', 'الدولة')">
                <x-ui.select name="specimen_country">
                    <option>{{ $t('Saudi Arabia', 'المملكة العربية السعودية') }}</option>
                    <option>{{ $t('United Arab Emirates', 'الإمارات العربية المتحدة') }}</option>
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="$t('Note', 'ملاحظة')" class="sm:col-span-2">
                <x-ui.textarea name="specimen_note" rows="2" />
            </x-ui.field>
            <div>
                <x-ui.checkbox name="specimen_terms" checked :description="$t('You can read them first.', 'يمكنك قراءتها أولاً.')">{{ $t('I accept the terms', 'أوافق على الشروط') }}</x-ui.checkbox>
                <x-ui.switch name="specimen_switch" checked :description="$t('Stops new issuing on the platform.', 'يوقف الإصدار الجديد على المنصة.')">{{ $t('Kill switch', 'مفتاح الإيقاف') }}</x-ui.switch>
            </div>
            <fieldset>
                <legend class="text-sm font-medium">{{ $t('Invoice type', 'نوع الفاتورة') }}</legend>
                <x-ui.radio name="{{ $scope }}-kind" value="standard" checked>{{ $t('Tax invoice', 'فاتورة ضريبية') }}</x-ui.radio>
                <x-ui.radio name="{{ $scope }}-kind" value="simplified">{{ $t('Simplified invoice', 'فاتورة مبسطة') }}</x-ui.radio>
            </fieldset>
            <fieldset class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium">{{ $t('Where your company is registered', 'مكان تسجيل شركتك') }}</legend>
                <x-ui.radio tile name="{{ $scope }}-country" value="a" checked :description="$t('Available now', 'متاح الآن')">{{ $t('Saudi Arabia', 'المملكة العربية السعودية') }}</x-ui.radio>
                <x-ui.radio tile name="{{ $scope }}-country" value="b" :description="$t('Coming soon', 'قريباً')">{{ $t('United Arab Emirates', 'الإمارات العربية المتحدة') }}</x-ui.radio>
            </fieldset>
        </div>
    </section>

    {{-- Feedback --}}
    <section aria-labelledby="{{ $scope }}-feedback" class="space-y-4">
        <h2 id="{{ $scope }}-feedback" class="text-heading font-semibold">{{ $t('Messages and states', 'الرسائل والحالات') }}</h2>
        <div class="grid gap-3 lg:grid-cols-2">
            <x-ui.alert tone="success">{{ $t('Invoice issued and cleared.', 'صدرت الفاتورة واعتُمدت.') }}</x-ui.alert>
            <x-ui.alert tone="warning" :title="$t('Issued, not yet filed', 'صدرت ولم تُقدَّم بعد')">{{ $t('It will be sent when the authority can be reached.', 'ستُرسل عند إمكانية الوصول إلى الهيئة.') }}</x-ui.alert>
            <x-ui.alert tone="danger">{{ $t('The authority rejected the invoice.', 'رفضت الهيئة الفاتورة.') }}</x-ui.alert>
            <x-ui.alert tone="info">{{ $t('Your company is being reviewed.', 'شركتك قيد المراجعة.') }}</x-ui.alert>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($states as $state)
                <x-ui.badge :state="$state" />
            @endforeach
        </div>
    </section>

    {{-- Structure --}}
    <section aria-labelledby="{{ $scope }}-structure" class="space-y-4">
        <h2 id="{{ $scope }}-structure" class="text-heading font-semibold">{{ $t('Structure', 'البنية') }}</h2>

        <x-ui.page-header :eyebrow="$t('Invoices', 'الفواتير')" :title="$t('INV-2026-00418', 'INV-2026-00418')" :description="$t('Issued 14 October, cleared by the authority.', 'صدرت في 14 أكتوبر واعتمدتها الهيئة.')" class="rounded-card border border-line bg-surface p-5">
            <x-slot:breadcrumb>
                <x-ui.breadcrumb :items="[['label' => $t('Portal', 'البوابة'), 'href' => '#'], ['label' => $t('Invoices', 'الفواتير'), 'href' => '#'], ['label' => 'INV-2026-00418']]" />
            </x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary">{{ $t('Download PDF', 'تنزيل PDF') }}</x-ui.button>
                <x-ui.menu :label="$t('More', 'المزيد')">
                    <x-ui.menu-item href="#">{{ $t('Email to buyer', 'إرسال إلى المشتري') }}</x-ui.menu-item>
                    <x-ui.menu-item href="#">{{ $t('Issue a credit note', 'إصدار إشعار دائن') }}</x-ui.menu-item>
                    <x-ui.menu-item type="button" tone="danger">{{ $t('Cancel draft', 'إلغاء المسودة') }}</x-ui.menu-item>
                </x-ui.menu>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.section-heading :eyebrow="$t('Where we work', 'أين نعمل')" :title="$t('One account. The rules of each country.', 'حساب واحد. وقواعد كل دولة.')" :pair="$t('حساب واحد. وقواعد كل دولة.', 'One account. The rules of each country.')" />

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat :label="$t('Cleared this month', 'معتمدة هذا الشهر')" value="1,284" tone="success" />
            <x-ui.stat :label="$t('Waiting to file', 'بانتظار التقديم')" value="3" tone="warning" />
            <x-ui.stat :label="$t('Rejected', 'مرفوضة')" value="0" />
        </div>

        <x-ui.stepper :current="1" :steps="[
            ['title' => $t('Account', 'الحساب'), 'hint' => $t('Who you are', 'من أنت')],
            ['title' => $t('Company', 'الشركة'), 'hint' => $t('Its legal name', 'اسمها القانوني')],
            ['title' => $t('Registration', 'التسجيل'), 'hint' => $t('What the authority knows', 'ما تعرفه الهيئة')],
            ['title' => $t('Review', 'المراجعة'), 'hint' => $t('Check and send', 'راجع وأرسل')],
        ]" />

        <x-ui.tabs id="{{ $scope }}-tabs" :label="$t('Country', 'الدولة')" :tabs="['one' => $t('Saudi Arabia', 'المملكة العربية السعودية'), 'two' => $t('United Arab Emirates', 'الإمارات العربية المتحدة')]">
            <x-ui.tab-panel id="{{ $scope }}-tabs" name="one" selected>
                <x-ui.card :title="$t('ZATCA Fatoora', 'فاتورة - هيئة الزكاة')" :description="$t('Clearance and reporting', 'الاعتماد والإبلاغ')">
                    <p class="text-sm text-muted">{{ $t('A card: a line and a surface. Tables run to its edges with flush.', 'بطاقة: حد وسطح. تمتد الجداول إلى حوافها مع flush.') }}</p>
                </x-ui.card>
            </x-ui.tab-panel>
            <x-ui.tab-panel id="{{ $scope }}-tabs" name="two">
                <x-ui.card :title="$t('Peppol PINT AE', 'Peppol PINT AE')">
                    <p class="text-sm text-muted">{{ $t('The second panel of the tabs.', 'اللوحة الثانية من علامات التبويب.') }}</p>
                </x-ui.card>
            </x-ui.tab-panel>
        </x-ui.tabs>

        <x-ui.card :title="$t('Recent invoices', 'أحدث الفواتير')" flush>
            <x-slot:actions>
                <x-ui.button type="button" size="sm" variant="secondary" data-modal-open="{{ $scope }}-modal">{{ $t('Open a dialog', 'افتح نافذة') }}</x-ui.button>
            </x-slot:actions>
            <x-ui.table :primary="[0, 1, 2, 3]">
                <x-slot:head>
                    <x-ui.th>{{ $t('Number', 'الرقم') }}</x-ui.th>
                    <x-ui.th>{{ $t('Buyer', 'المشتري') }}</x-ui.th>
                    <x-ui.th align="right">{{ $t('Total', 'الإجمالي') }}</x-ui.th>
                    <x-ui.th>{{ $t('State', 'الحالة') }}</x-ui.th>
                </x-slot:head>
                <tr>
                    <td class="px-6 py-4 font-mono tabular-nums" dir="ltr">INV-2026-00418</td>
                    <td class="px-6 py-4 text-muted">{{ $t('Northwind Trading', 'نورثويند للتجارة') }}</td>
                    <td class="px-6 py-4 text-end"><x-ui.amount value="1150" currency="SAR" /></td>
                    <td class="px-6 py-4"><x-ui.badge state="cleared" /></td>
                </tr>
                <x-ui.empty columns="4">{{ $t('An empty table says so, centred under its columns.', 'يقول الجدول الفارغ ذلك، في المنتصف تحت أعمدته.') }}</x-ui.empty>
            </x-ui.table>
        </x-ui.card>

        {{ $sample->links() }}

        {{-- A history list's filters: two on, so the chips show, and the
             CSV. The drawer folds away on a phone. --}}
        @php
            $specimenList = \App\Http\Listing\ListState::fromQuery(new \App\Http\Listing\ListSpec(
                key: 'admin.design-system',
                sorts: ['newest' => new \App\Http\Listing\Sort($t('Newest', 'الأحدث'), 'created_at'), 'amount' => new \App\Http\Listing\Sort($t('Amount', 'المبلغ'), 'total')],
                defaultSort: 'newest',
                filters: [
                    new \App\Http\Listing\Filters\Search('q', $t('Search', 'بحث'), ['invoice_number']),
                    new \App\Http\Listing\Filters\DateRange('issue_date', $t('Issue date', 'تاريخ الإصدار')),
                    new \App\Http\Listing\Filters\NumberRange('total', $t('Total', 'الإجمالي')),
                    new \App\Http\Listing\Filters\Choice('status', $t('Status', 'الحالة'), ['issued' => $t('Issued', 'صادرة'), 'draft' => $t('Draft', 'مسودة')], 'status'),
                ],
            ), ['status' => 'issued', 'amount_min' => '100'], url('/admin/design-system'))->with([], url('/admin/design-system'), null, fn (): string => '#');
        @endphp
        <x-ui.list-filters :list="$specimenList" />

        {{-- What a list and a long page do on a phone, drawn compact here
             at any width: the first row expanded, the second collapsed. --}}
        <h3 class="pt-4 text-sm font-semibold text-subtle">{{ $t('On a small screen: rows collapse to their key fields, and open in place', 'على الشاشات الصغيرة: تُطوى الصفوف إلى حقولها الأساسية وتُفتح في مكانها') }}</h3>
        <div class="max-w-sm overflow-hidden rounded-card border border-line">
            <x-ui.table :primary="[0, 1, 4, 3]" collapsible="always">
                <x-slot:head>
                    <x-ui.th>{{ $t('Invoice', 'الفاتورة') }}</x-ui.th>
                    <x-ui.th>{{ $t('Buyer', 'المشتري') }}</x-ui.th>
                    <x-ui.th>{{ $t('Issued', 'تاريخ الإصدار') }}</x-ui.th>
                    <x-ui.th>{{ $t('Status', 'الحالة') }}</x-ui.th>
                    <x-ui.th align="right">{{ $t('Total', 'الإجمالي') }}</x-ui.th>
                    <x-ui.th>{{ $t('Actions', 'الإجراءات') }}</x-ui.th>
                </x-slot:head>
                <tr data-expanded>
                    <td class="px-6 py-4 font-mono tabular-nums" dir="ltr">INV-2026-00418</td>
                    <td class="px-6 py-4 text-muted">{{ $t('Northwind Trading', 'نورثويند للتجارة') }}</td>
                    <td class="px-6 py-4 text-muted"><x-ui.date value="2026-10-14" /></td>
                    <td class="px-6 py-4"><x-ui.badge state="cleared" /></td>
                    <td class="px-6 py-4"><x-ui.amount value="1150" /></td>
                    <td class="px-6 py-4"><x-ui.button size="sm" variant="secondary" href="#">{{ $t('Download PDF', 'تنزيل PDF') }}</x-ui.button></td>
                </tr>
                <tr>
                    <td class="px-6 py-4 font-mono tabular-nums" dir="ltr">INV-2026-00419</td>
                    <td class="px-6 py-4 text-muted">{{ $t('Gulf Supplies', 'إمدادات الخليج') }}</td>
                    <td class="px-6 py-4 text-muted"><x-ui.date value="2026-10-15" /></td>
                    <td class="px-6 py-4"><x-ui.badge state="pending" /></td>
                    <td class="px-6 py-4"><x-ui.amount value="420" /></td>
                    <td class="px-6 py-4"><x-ui.button size="sm" variant="secondary" href="#">{{ $t('Download PDF', 'تنزيل PDF') }}</x-ui.button></td>
                </tr>
            </x-ui.table>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <x-ui.section :title="$t('Buyer', 'المشتري')" :description="$t('Open: a section that matters on every screen.', 'مفتوح: قسم مهم على كل شاشة.')">
                <p class="text-sm text-muted">{{ $t('Its fields are here.', 'حقوله هنا.') }}</p>
            </x-ui.section>
            <x-ui.section closed :title="$t('Notes and discount', 'الملاحظات والخصم')" :description="$t('Closed: optional, folded away on a small screen.', 'مغلق: اختياري، يُطوى على الشاشة الصغيرة.')">
                <p class="text-sm text-muted">{{ $t('Opened by its heading, or by an error inside it.', 'يُفتح من عنوانه أو عند وجود خطأ بداخله.') }}</p>
            </x-ui.section>
        </div>

        <div class="rounded-card border border-line bg-surface px-5">
            <x-ui.disclosure :summary="$t('Do I need my own ERP?', 'هل أحتاج إلى نظام خاص بي؟')" open>
                {{ $t('No. Every invoice can be issued in the portal.', 'لا. يمكن إصدار كل فاتورة من البوابة.') }}
            </x-ui.disclosure>
            <x-ui.disclosure :summary="$t('A closed disclosure', 'تفصيل مغلق')" class="border-b-0">
                {{ $t('Its answer.', 'إجابته.') }}
            </x-ui.disclosure>
        </div>

        <nav class="max-w-xs space-y-1 rounded-card border border-line bg-surface p-3" aria-label="{{ $t('Sidebar', 'الشريط الجانبي') }}">
            <x-ui.nav-link href="#" active>
                <x-slot:icon><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14h6m-6-4h6m4 10H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2z" /></svg></x-slot:icon>
                {{ $t('Invoices', 'الفواتير') }}
            </x-ui.nav-link>
            <x-ui.nav-link href="#">
                <x-slot:icon><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg></x-slot:icon>
                {{ $t('Customers', 'العملاء') }}
            </x-ui.nav-link>
        </nav>

        <x-ui.modal id="{{ $scope }}-modal" :title="$t('Revoke this licence?', 'إلغاء هذا الترخيص؟')">
            {{ $t('The browser’s own dialog: focus stays inside, Escape closes it.', 'نافذة المتصفح نفسها: يبقى التركيز داخلها ويغلقها مفتاح Escape.') }}
            <x-slot:footer>
                <x-ui.button type="button" variant="ghost" data-modal-close>{{ $t('Cancel', 'إلغاء') }}</x-ui.button>
                <x-ui.button type="button" variant="danger" data-modal-close>{{ $t('Revoke', 'إلغاء الترخيص') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </section>
</div>
