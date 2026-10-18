<?php

declare(strict_types=1);

namespace App\Domains\Platform\Customization;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What every settings page posts, handled once: one customization, set at
 * the page's level or reset to what the level above says.
 *
 * Only a key the page shows (Customizations::describe for its level) is
 * accepted, so a page for one's own preferences cannot set an
 * organization's value by naming it.
 */
class CustomizationForm
{
    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /**
     * @return string what was done, for the page to say
     *
     * @throws ValidationException
     * @throws DomainException when a level above locked it
     */
    public function apply(Request $request, string $key, Level $level, Scope $scope, ?string $actorId): string
    {
        $shown = array_map(fn (Resolved $resolved): string => $resolved->definition->key, $this->customizations->describe($level, $scope));

        if (! in_array($key, $shown, true)) {
            abort(404);
        }

        $definition = $this->customizations->definition($key);

        if ($request->boolean('reset')) {
            $this->customizations->reset($key, $level, $scope, $actorId);

            return __(':setting now follows the level above.', ['setting' => $definition->label]);
        }

        $this->customizations->set($key, $level, $scope, $request->input('value'), $actorId, $request->boolean('locked'));

        return __(':setting saved.', ['setting' => $definition->label]);
    }
}
