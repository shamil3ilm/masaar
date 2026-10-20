<?php

use App\Domains\Billing\BillingServiceProvider;
use App\Domains\Compliance\Countries\SaudiArabia\ModuleServiceProvider as SaudiArabiaModule;
use App\Domains\Compliance\Countries\UnitedArabEmirates\ModuleServiceProvider as UnitedArabEmiratesModule;
use App\Domains\Compliance\Networks\Peppol\NetworkServiceProvider as PeppolNetwork;
use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    BillingServiceProvider::class,
    SaudiArabiaModule::class,
    UnitedArabEmiratesModule::class,
    PeppolNetwork::class,
];
