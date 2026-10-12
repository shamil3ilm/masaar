<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        /*
         * The alert channel is appended rather than configured in.
         *
         * Whether a critical event reaches a human is not a thing a
         * deployment should be able to switch off by forgetting an entry in
         * LOG_STACK - and it would be forgotten, because a .env copied six
         * months ago does not grow new keys. The readiness guide already
         * records that exact failure for the rate-limit pair.
         *
         * Appended and de-duplicated, so naming it explicitly is harmless.
         */
        'stack' => [
            'driver' => 'stack',
            'channels' => array_values(array_unique(array_merge(
                array_filter(array_map('trim', explode(',', (string) env('LOG_STACK', 'single')))),
                ['alerts'],
            ))),
            'ignore_exceptions' => false,
        ],

        /*
         * Where a critical event goes.
         *
         * Nothing told anyone when a submission failed. Seven places already
         * raise Log::critical - a kill switch thrown, ZATCA answering a
         * submission the database then failed to record, an offline item
         * accepted but not written down - and every one of them went to a
         * file on a server nobody reads.
         *
         * Always a file of its own first, so an alert survives the webhook
         * being wrong, expired or rate limited: a dropped alert about a
         * dropped submission is the worst of both. Slack is added only when
         * a URL is configured, because naming an unconfigured slack channel
         * in a stack makes every write throw.
         */
        'alerts' => [
            'driver' => 'stack',
            'channels' => array_values(array_filter([
                'alert-file',
                env('LOG_SLACK_WEBHOOK_URL') ? 'slack' : null,
            ])),
            'ignore_exceptions' => true,
        ],

        'alert-file' => [
            'driver' => 'daily',
            'path' => storage_path('logs/alerts.log'),
            'level' => env('LOG_ALERT_LEVEL', 'critical'),
            'days' => env('LOG_ALERT_DAYS', 90),
            'replace_placeholders' => true,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_ALERT_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |--------------------------------------------------------------------------
        | ZATCA Compliance Channels
        |--------------------------------------------------------------------------
        |
        | These channels handle logging for ZATCA compliance operations.
        | Logs are retained for audit compliance requirements.
        |
        */

        'zatca-submissions' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zatca/submissions.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'zatca-compliance' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zatca/compliance.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'zatca-webhooks' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zatca/webhooks.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'zatca-audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zatca/audit.log'),
            'level' => 'info',
            'days' => 365,
            'replace_placeholders' => true,
        ],

        'zatca-errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/zatca/errors.log'),
            'level' => 'error',
            'days' => 90,
            'replace_placeholders' => true,
        ],

    ],

];
