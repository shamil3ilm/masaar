<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Split by audience rather than by domain, because the audience is what
| decides the guard. Each file declares its own middleware stack once, at the
| top, so the question "what protects this endpoint?" is answered by the file
| it sits in.
|
|   public.php      no credential. The entire unauthenticated surface.
|   tenant.php      jwt.auth      a signed-in person acting for one organization
|   partner.php     license       an ERP acting for its customer, over /v1
|   platform.php    platform.admin  Masaar staff, across every tenant
|
| Splitting by domain instead would repeat each guard across seven files and
| let them drift apart. RouteAuthPostureTest sweeps the router and fails the
| build on any route that carries no guard and is not declared public.
|
| Licensing has its own file, loaded separately in bootstrap/app.php.
|
*/

require __DIR__.'/api/public.php';
require __DIR__.'/api/tenant.php';
require __DIR__.'/api/partner.php';
require __DIR__.'/api/platform.php';
