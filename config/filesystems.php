<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Where backups go (config/backup.php): any object store that speaks
         * the S3 protocol. 's3' below is the protocol driver, not a provider -
         * which store it is lives entirely in BACKUP_STORAGE_*, so changing
         * provider is a change of environment, not of code.
         */
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_STORAGE_KEY'),
            'secret' => env('BACKUP_STORAGE_SECRET'),
            'region' => env('BACKUP_STORAGE_REGION'),
            'bucket' => env('BACKUP_STORAGE_BUCKET'),
            // Compose forwards an unset variable as an empty string, and to
            // the client an empty endpoint is not the same as none.
            'endpoint' => env('BACKUP_STORAGE_ENDPOINT') ?: null,
            // Most stores other than the protocol's originator address a
            // bucket by path, not by subdomain.
            'use_path_style_endpoint' => (bool) env('BACKUP_STORAGE_PATH_STYLE', true),
            // The S3 client library has attached a checksum to every upload
            // since 3.337, as chunked trailers many stores refuse with 501
            // NotImplemented. Sent only where an operation requires one.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'visibility' => 'private',
            // A write that fails must fail the backup, not return false.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
