<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',

            /*
             * A read replica, when there is one.
             *
             * Laravel splits a connection only when a 'read' key is present,
             * so with DB_READ_HOST unset everything below collapses to the
             * single-host connection this has always been. Several hosts may
             * be given comma-separated; one is picked per request.
             *
             * sticky is not optional here, and it is set rather than exposed.
             * Illuminate\Database\Connection::getReadPdo already sends every
             * read inside a transaction to the write connection, and
             * Query\Builder::lock() sets useWritePdo, so the locked
             * read-modify-write sequences this application depends on - the
             * ICV allocation, License::changeWithAudit, SubmissionLedger::claim
             * - are safe on a replica without any help.
             *
             * What is not safe is read-after-write outside a transaction, and
             * this codebase does that deliberately and often: Submitter's
             * caller refreshes the invoice once issuance has committed, and
             * the licence services return fresh() after changeWithAudit. By
             * then the transaction is closed, so without sticky those reads
             * can land on a replica that has not caught up and report the row
             * as it was before the write. ReplicaPostureTest holds this.
             *
             * Still to decide when a replica actually appears: cache, queue
             * and session all default to the database driver on this same
             * connection. The queue is fine - DatabaseQueue::pop reserves
             * under lockForUpdate - but Cache::get is not, and the kill-switch
             * registry is a cache read taken on every issuance and every
             * submission. A lagging replica there means an operator's kill
             * switch takes effect late, during an incident. Point
             * DB_CACHE_CONNECTION and SESSION_CONNECTION at an unsplit
             * connection, or move them to Redis.
             */
            ...(env('DB_READ_HOST') ? [
                'read' => [
                    'host' => array_map('trim', explode(',', (string) env('DB_READ_HOST'))),
                ],
                'write' => [
                    'host' => [env('DB_HOST', '127.0.0.1')],
                ],
                'sticky' => true,
            ] : []),

            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],

            /*
             * How backup:run calls mysqldump (config/backup.php).
             *
             * A single transaction gives a consistent snapshot of InnoDB
             * without locking the tables invoices are being written to.
             * --no-tablespaces, because MySQL 8 otherwise wants the PROCESS
             * privilege an application user has no reason to hold.
             *
             * DB_DUMP_SSL_FLAG exists for the image: its client is MariaDB's,
             * which insists on verifying the server certificate, and the
             * compose MySQL presents a self-signed one on a private network
             * the application itself connects over without TLS. 'skip-ssl'
             * matches that. Unset, the client's default applies.
             */
            'dump' => [
                'use_single_transaction' => true,
                'add_extra_option' => '--no-tablespaces',
                'set_ssl_flag' => (string) env('DB_DUMP_SSL_FLAG', ''),
            ],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Issuing Lock Wait
    |--------------------------------------------------------------------------
    |
    | Seconds an issuance waits for its device's lock, or a number series
    | another issuance holds, before it is answered busy (503 SYS_008, with
    | Retry-After the same seconds). Set on the database session for the
    | issuing transaction only and put back after it (IssuingLifecycle);
    | the server's own setting, fifty seconds on MySQL as shipped, is left
    | alone. A device's lock is held for one signature, about half a
    | second, so a wait this long means the device is backed up.
    |
    */

    'issuing_lock_wait' => max(1, (int) env('ISSUING_LOCK_WAIT_SECONDS', 5)),

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
