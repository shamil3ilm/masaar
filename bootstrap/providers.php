<?php

use App\Domains\Compliance\Fatoora\FatooraServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PeppolServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    FatooraServiceProvider::class,
    PeppolServiceProvider::class,
];
