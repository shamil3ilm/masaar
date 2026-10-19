<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
{{--
    Signing in, signing up and the account steps around them.

    The page's own title is the heading, with the product's mark above it
    and a line under it on what the page is for; the form sits on one card on
    the warm ground, the width the page asks for (@section('width')).
--}}
<body class="min-h-screen bg-ground text-fg">
    <div class="flex min-h-screen flex-col">
        <header class="mx-auto flex w-full max-w-page items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="{{ url('/') }}" class="inline-flex rounded">
                <x-logo :size="32" name name-class="text-lg font-semibold tracking-tight" />
            </a>
            <x-locale-switch />
        </header>

        <main id="content" class="flex-1 px-4 pb-12 pt-4 sm:px-6 sm:pt-8">
            <div class="mx-auto w-full @yield('width', 'max-w-md')">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">@yield('heading', $__env->yieldContent('title'))</h1>
                    @hasSection('subtitle')
                        <p class="mt-2 text-sm text-muted sm:text-base">@yield('subtitle')</p>
                    @endif
                </div>

                <div class="rounded-card border border-line bg-surface p-5 shadow-card sm:p-8">
                    @if ($errors->any())
                        <x-ui.alert tone="danger" :title="__('Please correct the following:')" class="mb-6">
                            <ul class="list-disc space-y-1 ps-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    @if (session('status'))
                        <x-ui.alert tone="info" class="mb-6">{{ session('status') }}</x-ui.alert>
                    @endif

                    @yield('content')
                </div>
            </div>
        </main>

        <footer class="mx-auto flex w-full max-w-page flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-6 text-sm text-subtle sm:px-6">
            <p>{{ __('Powered by Masaar') }}</p>
            <nav class="flex flex-wrap gap-x-5" aria-label="{{ __('Legal') }}">
                <a href="{{ route('privacy') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Privacy') }}</a>
                <a href="{{ route('terms') }}" class="inline-flex min-h-11 items-center hover:text-fg hover:underline underline-offset-4">{{ __('Terms') }}</a>
            </nav>
        </footer>
    </div>
    <x-ui.strings />
    <x-ui.confirm-dialog />
</body>
</html>
