<?php

use App\Domains\Compliance\Countries\SaudiArabia\ModuleServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PeppolServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    ModuleServiceProvider::class,
    PeppolServiceProvider::class,
];
