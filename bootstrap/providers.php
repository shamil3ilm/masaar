<?php

use App\Providers\AppServiceProvider;
use App\Providers\ComplianceServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PeppolServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    ComplianceServiceProvider::class,
    PeppolServiceProvider::class,
];
