<?php

use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Compliance Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks support ZATCA compliance monitoring and maintenance.
| See docs/PRODUCTION-READINESS.md for operational guidelines.
|
| Every task is onOneServer: each replica runs this scheduler, and
| withoutOverlapping only stops two copies running at once, so a replica
| that finished first let the other run the task again - certificate
| notices sent twice, usage billed twice. ScheduledCommandTest holds it.
|
*/

// Index health check - runs every 15 minutes
Schedule::command('compliance:index-health --alert')
    ->onOneServer()
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/index-health.log'));

// Partition maintenance - runs monthly on the 1st at 3 AM
Schedule::command('compliance:partition-maintenance --create-future --months-ahead=2')
    ->onOneServer()
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/partition-maintenance.log'));

// The contact form's senders' network address and browser, kept for spam
// review only: cleared once a message is older than the keeping period.
Schedule::command('website:prune-contact-details')
    ->onOneServer()
    ->dailyAt('02:30')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/contact-prune.log'));

/*
|--------------------------------------------------------------------------
| Licensing Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks support license management and usage tracking.
|
*/

// Clean up expired rate limits - runs hourly
Schedule::command('license:cleanup-rate-limits')
    ->onOneServer()
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/license-cleanup.log'));

// Check license expirations - runs daily at midnight
Schedule::command('license:check-expiration')
    ->onOneServer()
    ->dailyAt('00:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/license-expiration.log'));

// Tell organizations their plan ends soon, while there is time to pay.
Schedule::command('billing:remind-renewals')
    ->onOneServer()
    ->dailyAt('09:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/billing-reminders.log'));

// The platform's own invoices: those that fell due become overdue, before
// the day's reminders go out.
Schedule::command('billing:refresh-receivables')
    ->onOneServer()
    ->dailyAt('00:30')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/billing-receivables.log'));

// And their reminders, on each customer's reminder days, in both languages.
Schedule::command('billing:remind-receivables')
    ->onOneServer()
    ->dailyAt('09:15')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/billing-receivables.log'));

// Report usage metrics to license server - runs hourly
Schedule::command('license:report-usage')
    ->onOneServer()
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->when(fn () => config('platform-license.enabled', false))
    ->appendOutputTo(storage_path('logs/license-usage.log'));

/*
|--------------------------------------------------------------------------
| Invoice schedules
|--------------------------------------------------------------------------
|
| Organizations' recurring invoices and instalment plans: each period's
| invoice, made soon after its first day begins on the organization's
| clock, and the runs that failed tried again. A period is invoiced once
| however often this runs (ScheduleRunner).
|
*/
Schedule::command('invoices:run-schedules')
    ->onOneServer()
    ->everyFifteenMinutes()
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/invoice-schedules.log'));

/*
|--------------------------------------------------------------------------
| ZATCA Offline Queue Scheduled Tasks
|--------------------------------------------------------------------------
|
| These tasks handle offline mode recovery and queue processing.
| Critical for POS/retail scenarios with intermittent connectivity.
|
*/

// Process offline queue - runs every 5 minutes when auto-recovery enabled
Schedule::command('fatoora:process-offline --limit=50')
    ->onOneServer()
    ->everyFiveMinutes()
    // Ten minutes, not the default.
    //
    // withoutOverlapping() defaults to a 1440 minute expiry, so a
    // runInBackground() child killed by OOM, a docker stop or a deploy leaves
    // the mutex held and this drain blocked for twenty-four hours, with
    // nothing logged. Simplified invoices have twenty-four hours to be
    // reported, so that is precisely one deadline's worth of silence.
    //
    // Two ticks' worth of grace: the task runs every five minutes, and a run
    // that is genuinely still going at ten is one this should not start a
    // second copy of anyway.
    ->withoutOverlapping(10)
    ->runInBackground()
    // The file is config/fatoora.php. A config() call naming a file that does
    // not exist returns its default rather than failing, so a wrong namespace
    // here reads as "always enabled" and the setting silently does nothing.
    ->when(fn () => config('fatoora.offline.auto_recovery.enabled', true))
    ->appendOutputTo(storage_path('logs/zatca-offline-queue.log'));

// Check certificate expiry - runs daily at 8 AM with notifications
Schedule::command('fatoora:check-certificate --notify')
    ->onOneServer()
    ->dailyAt('08:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/zatca-certificate.log'));

// A till asked to retire with documents still to file is retired once ZATCA
// holds them all, without anyone asking again; its organization is told.
Schedule::command('fatoora:finish-retirements')
    ->onOneServer()
    ->dailyAt('05:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/zatca-certificate.log'));

// The CA's revocation lists, for every certificate a device signs or files
// with: a revoked one is recorded, its organization and the platform told,
// and its device stops signing (Security Features Implementation Standard
// v1.2, section 2.1.3). The check before each stamp covers the hours between.
Schedule::command('fatoora:check-revocation')
    ->onOneServer()
    ->dailyAt('07:30')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/zatca-certificate.log'));

// Verify hash chain integrity - runs weekly on Sunday at 2 AM
// A deadline passes in silence. Every other alert here fires from the code
// path that failed, which covers the failures that happen and not the ones
// that stop happening - a worker that died, retries exhausted overnight, a
// submission left in flight. ZATCA gives a simplified invoice twenty-four
// hours, so this goes looking every hour and fails the run if any are past it.
Schedule::command('fatoora:overdue-submissions --alert')
    ->onOneServer()
    ->hourly()
    ->withoutOverlapping(30);

// Submissions left in flight with nothing coming back for them - a dead
// worker, a lost job. Every ten minutes, so an invoice is not blocked from
// filing for longer than the stale window plus this.
Schedule::command('fatoora:recover-submissions')
    ->onOneServer()
    ->everyTenMinutes()
    ->withoutOverlapping(10);

Schedule::command('fatoora:verify-hash-chain')
    ->onOneServer()
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/zatca-hash-chain.log'));

// Clean up old offline queue items - runs daily at 4 AM
Schedule::command('compliance:cleanup-offline-queue')
    ->onOneServer()
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/zatca-queue-cleanup.log'));

/*
|--------------------------------------------------------------------------
| Peppol (UAE)
|--------------------------------------------------------------------------
|
| The service provider calls back with each document's status; this asks
| about the sent documents whose callback never came. Only where a provider
| is connected, or the simulator stands in for one - without either nothing
| is ever sent.
|
*/
Schedule::command('peppol:poll-status')
    ->onOneServer()
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => filled(config('peppol.asp.base_url')) || config('peppol.gateway') === 'simulator')
    ->appendOutputTo(storage_path('logs/peppol-status.log'));

// UAE organizations with no service provider appointed, reminded on each
// configured day before their last date to appoint one (UAE Electronic
// Invoicing Guidelines v1.1, p14, p19). Nothing while no date is set.
Schedule::command('uae:remind-provider-appointments')
    ->onOneServer()
    ->dailyAt('08:00')
    ->withoutOverlapping(30)
    ->when(fn () => filled(config('peppol.countries.AE.appointment.deadline')) || config('peppol.countries.AE.enabled'))
    ->appendOutputTo(storage_path('logs/peppol-status.log'));

// A document whose worker died mid-call is looked up with the provider by
// its own reference, then recorded as sent or sent again (UAE Electronic
// Invoicing Guidelines v1.1, p46: pending invoices go out on resumption).
Schedule::command('peppol:recover-sending')
    ->onOneServer()
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => filled(config('peppol.asp.base_url')) || config('peppol.gateway') === 'simulator')
    ->appendOutputTo(storage_path('logs/peppol-status.log'));

// A failed report to the authority is the taxpayer's to see through (UAE
// Electronic Invoicing Guidelines v1.1, p45): asked again every hour.
Schedule::command('peppol:retry-reports')
    ->onOneServer()
    ->hourly()
    ->withoutOverlapping(30)
    ->when(fn () => filled(config('peppol.asp.base_url')) || config('peppol.gateway') === 'simulator')
    ->appendOutputTo(storage_path('logs/peppol-status.log'));

// The partner API's request log, past each organization's keeping period
// (integrations.request_log_days).
Schedule::command('integrations:prune-request-log')
    ->onOneServer()
    ->dailyAt('04:45')
    ->withoutOverlapping(30);

// Uploaded CSV import files past their keeping period (imports.keep_days).
Schedule::command('imports:prune')
    ->onOneServer()
    ->dailyAt('04:50')
    ->withoutOverlapping(30);

// Imported bank statement and settlement files past their keeping period
// (billing.reconciliation.keep_days). Their lines stay.
Schedule::command('finance:prune-statements')
    ->onOneServer()
    ->dailyAt('04:55')
    ->withoutOverlapping(30);

// Webhook delivery logs past their retention (WebhookLog::RETENTION_DAYS).
Schedule::command('model:prune', ['--model' => [WebhookLog::class]])
    ->onOneServer()
    ->dailyAt('04:30')
    ->withoutOverlapping(30);

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| Every replica runs this scheduler, so each is onOneServer: one archive a
| night, not one per container. That needs a cache every replica shares,
| which production's Redis is. Clean before the run, so the new archive is
| never what pruning removes; monitor well after, so a run that has not
| finished is not reported stale. Failures page via AlertOnBackupTrouble.
|
*/
Schedule::command('backup:clean')
    ->dailyAt('01:00')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('backup:run')
    ->dailyAt('01:30')
    ->onOneServer()
    ->withoutOverlapping(120);

Schedule::command('backup:monitor')
    ->dailyAt('06:00')
    ->onOneServer();
