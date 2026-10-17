<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Platform\Access\Feature;

/**
 * Which of the platform's features exist in a country, for Access.
 *
 * Asked whatever the country's switch says: a feature the country does not
 * have is refused as not part of it, and one it has is refused as switched
 * off only when that feature starts new work with the authority.
 */
interface FeatureSupport
{
    /** Whether the feature is part of this country's e-invoicing at all. */
    public function supportsFeature(Feature $feature): bool;

    /**
     * The platform kill switches this country's own steps also obey for the
     * feature, beyond those every country does (Feature::killSwitches()).
     *
     * @return list<string> KillSwitch::SWITCH_* names
     */
    public function killSwitchesFor(Feature $feature): array;
}
