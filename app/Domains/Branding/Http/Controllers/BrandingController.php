<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use App\Domains\Branding\DTOs\ThemeDefinition;
use App\Domains\Branding\Exceptions\InvalidThemeException;
use App\Domains\Branding\Http\Requests\ThemeRequest;
use App\Domains\Branding\Services\ThemeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Editing a theme: the platform's from the admin console, an organization's
 * from its portal. The two differ in whose theme it is and who may change it,
 * and nothing else.
 */
abstract class BrandingController extends Controller
{
    public function __construct(protected readonly ThemeService $themes) {}

    /**
     * Whose theme this request edits: null for the platform's.
     */
    abstract protected function owner(Request $request): ?string;

    /**
     * The view and route names this editor lives under.
     */
    abstract protected function view(): string;

    abstract protected function route(): string;

    public function edit(Request $request): View
    {
        $owner = $this->owner($request);
        $stored = $this->themes->storedDefinition($owner);

        return view($this->view(), [
            'stored' => $stored,
            'effective' => $this->themes->forScope($owner ?? ThemeService::PLATFORM),
            'assets' => $this->themes->storedAssets($owner),
            'json' => json_encode($stored->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'route' => $this->route(),
            'inherits' => $owner !== null,
        ]);
    }

    public function update(ThemeRequest $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $current = $this->themes->storedDefinition($owner);

        $this->keep($owner, $request->definition($current), [
            'logo' => $request->file('logo'),
            'favicon' => $request->file('favicon'),
        ], [
            'logo' => $request->boolean('remove_logo'),
            'favicon' => $request->boolean('remove_favicon'),
        ]);

        return redirect()->route($this->route().'.edit')->with('success', 'Branding saved.');
    }

    /**
     * Replace the theme with one written as JSON.
     */
    public function import(Request $request): RedirectResponse
    {
        $json = $request->validate(['theme_json' => ['required', 'string', 'max:20000']])['theme_json'];
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['theme_json' => 'That is not a JSON object.']);
        }

        try {
            $definition = ThemeDefinition::fromArray($decoded);
        } catch (InvalidThemeException $e) {
            throw ValidationException::withMessages(['theme_json' => $e->errors]);
        }

        $this->keep($this->owner($request), $definition);

        return redirect()->route($this->route().'.edit')->with('success', 'Theme imported.');
    }

    /**
     * The stored theme as a JSON file, to keep or to load elsewhere.
     */
    public function export(Request $request): StreamedResponse
    {
        $definition = $this->themes->storedDefinition($this->owner($request));

        return response()->streamDownload(
            fn () => print (json_encode($definition->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'theme.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->themes->reset($this->owner($request));

        return redirect()->route($this->route().'.edit')->with('success', 'Branding reset.');
    }

    /**
     * @param  array{logo?: mixed, favicon?: mixed}  $uploads
     * @param  array{logo?: bool, favicon?: bool}  $remove
     *
     * @throws ValidationException
     */
    private function keep(?string $owner, ThemeDefinition $definition, array $uploads = [], array $remove = []): void
    {
        try {
            $this->themes->save($owner, $definition, $uploads, $remove, Auth::id());
        } catch (InvalidThemeException $e) {
            throw ValidationException::withMessages(['theme' => $e->errors]);
        }
    }
}
