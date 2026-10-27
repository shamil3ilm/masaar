# How to

Audience: engineers making a common kind of change. Each recipe lists the
files to touch and the test that tells you it is done. Write the failing
test first, and finish with `php artisan test --parallel --processes=6` on
SQLite and MySQL ([TESTING-AND-GATES.md](TESTING-AND-GATES.md)).

## Add a setting

Settings are layered (shipped default, platform, organization, branch, till,
person); the model is in [CUSTOMIZATION.md](../architecture/CUSTOMIZATION.md).

1. Add a `new Definition(...)` to
   `app/Domains/Platform/Customization/Catalogue.php`: `key`, `group`,
   `label` and `help` (through `__()`), `type`, `default` and the `levels`
   that may set it. Every customization takes at least `Level::Platform`,
   `Level::Organization` and `Level::User`.
2. Read it with `Customizations::get($key, $scope)` (or `resolve()` for the
   value and where it came from), with a `Scope` such as
   `Scope::member($organizationId, $userId)`. Never read it from `config()`.
3. The settings pages list it on their own (`Customizations::describe()`);
   only a definition marked `ownScreen`, or a list of entries, needs a page
   of its own.
4. Add the Arabic for the label and help to `lang/ar.json`.

## Add a portal page

1. Add the route to the main portal group in `routes/web.php` (the one with
   `portal.tenant`, `portal.branch` and `org.writer`); it is named
   `portal.<name>`. A page only some customers may use goes behind a feature
   ([ACCESS.md](../architecture/ACCESS.md), "Adding a feature").
2. A thin controller in `app/Domains/<Domain>/Http/Controllers/`, taking the
   organization from the session (`ResolvesPortalOrganization`), never from
   input; queries live in a service.
3. A view in `resources/views/portal/` that `@extends('layouts.portal')` and
   is built from `x-ui` components: one `<x-ui.page-header>`, lists in
   `<x-ui.table :primary="[...]">` with `<x-ui.th>` and `<x-ui.empty>`,
   optional form parts in `<x-ui.section>`. `resources/views/portal/activity.blade.php`
   is a short example; `/admin/design-system` shows every component.
4. `ScreenRenderTest` renders every `portal.*` GET route on its own; seed the
   rows the page lists there if it needs them. An admin page also needs
   `admin.can:<permission>` on each action (`AdminRoutePermissionTest`).

## Add an API endpoint

1. Pick the file by who calls it: `routes/api/tenant.php` (signed-in person),
   `partner.php` (integration, `/api/v1`), `platform.php` (platform staff),
   `public.php` (nobody; also list it in
   `ApiRouteAuthPostureTest::PUBLIC_ROUTES` with a reason).
2. Validate with a `FormRequest` (`<Verb><Noun>Request`) and answer through
   `App\Http\Responses\ApiResponse` (`success`, `created`, `paginated`,
   `error` ...). The OpenAPI generator reads both.
3. Regenerate the description: `php artisan platform:openapi` writes
   `docs/openapi.yaml` and `resources/developers/openapi.json`. Commit both;
   `OpenapiDriftTest` fails until they match the routes.
4. Feature-test the endpoint, including another tenant's id answering `404`.

## Add a migration

1. Create `NNNN_what_it_does.php` in `database/migrations` with the next free
   number. One concern, a working `down()`, columns as literal calls.
2. Classify each new table in `tests/Feature/Architecture/TableTimestamps.php`
   (class a, b, c or d, tenant, actors) and give it the columns its class
   needs; use `RecordsActors` on the model for the actor columns.
3. `php artisan platform:schema` to rewrite `docs/schema.sql`
   (`SchemaDriftTest`).
4. Run the suite on MySQL too: SQLite accepts things MySQL refuses.

Rules: [CONVENTIONS.md](CONVENTIONS.md#migrations).

## Add a translation

Wrap the English in `__()` with the literal string as the key, and add the
same key to `lang/ar.json` with Modern Standard Arabic. Keep every
placeholder (`:name`), and write `:app` where the product's name goes.
`TranslationTest` fails on a missing key, a lost placeholder or a duplicate.

## Add an approval policy

How approvals behave is in [APPROVALS.md](../architecture/APPROVALS.md).

1. Put the action in a family implementing
   `App\Domains\Platform\Approvals\ApprovableActions` (`keys`, `label`,
   `permission`, `preview`, `execute`), or add it to an existing family in
   `app/Domains/Platform/Approvals/Actions/`.
2. Register the key in `ApprovalCatalogue::ACTIONS`; a country module calls
   `ApprovalCatalogue::extend($action, $family, $heldByDefault)` from its
   service provider instead.
3. In the controller, run it through `holdOrRun()` (the `HoldsForApproval`
   concern) so it is held when its policy is on and done directly when not.
4. Test both paths: held, then approved by someone else, and run directly.

## Add a webhook event

1. Add the name to `WebhookService::EVENTS` and its words to
   `WebhookEvents::label()` (with Arabic).
2. Say which countries send it in each module's
   `Presentation::sendsWebhookEvent()`.
3. Raise it with `WebhookService::dispatch($organizationId, $event, $payload)`
   from a listener or service; it queues one delivery job per endpoint.
4. Document it in the Events table of `docs/integration/WEBHOOKS.md` and run
   `php artisan platform:openapi` (the event list is an enum there).

## Add a scheduled command

1. Write the command in the domain's `Console/` folder, named as an
   imperative phrase with no `Command` suffix. A domain whose `Console/`
   folder is new must be added to `withCommands()` in `bootstrap/app.php`.
2. Schedule it in `routes/console.php` with `->onOneServer()` and, if it can
   overlap, `->withoutOverlapping(<minutes>)`; `ScheduledCommandTest` checks
   both and that the command exists.
3. A number it uses (a batch size, a keeping period) is a setting with an
   entry in `.env.example`, not a literal (`SettingUseTest`,
   `EnvExampleTest`).

## Add a country module

Follow [ADDING-A-JURISDICTION.md](../architecture/ADDING-A-JURISDICTION.md):
a module under `app/Domains/Compliance/Countries/<Country>/` with the same
entry points as the others, registered in `bootstrap/providers.php`, its views
in `resources/views/countries/<country>/`, and nothing outside the module that
names it (`ModuleBoundaryTest`, `CountryCheckTest`).
