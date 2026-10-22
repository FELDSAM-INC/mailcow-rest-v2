<?php

use App\Support\Config\Env;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| Database and Redis (research R5, R6)
|--------------------------------------------------------------------------
|
| One MariaDB connection, `mysql`, without a table prefix: upstream tables are used by their real
| names and API-owned tables by their explicit `restv2_` names, so that one transaction covers an
| upstream write and its audit record. The connection never sets `time_zone`: mailcow's DATETIME
| columns hold local time written by NOW(), and App\Casts\MailcowDateTime converts them.
|
| Redis is mailcow's Redis. The `restv2` connection prefixes every API-owned key with `restv2:`;
| the `mailcow` connection reads mailcow's own keys (branding) and publishes to F2B_CHANNEL.
|
| Settings keep their mailcow.conf names and are read with Env::get: an empty value means unset
| (docs/conventions.md "Configuration").
|
*/

$redisHost = Env::get('REDIS_SLAVEOF_IP') ?? Env::get('REDISHOST', 'redis-mailcow');
$redisPort = Env::get('REDIS_SLAVEOF_IP') !== null ? Env::get('REDIS_SLAVEOF_PORT', 6379) : 6379;

return [

    // MariaDB only: tests never run on SQLite (constitution VII).
    'default' => 'mysql',

    'connections' => [

        'mysql' => [
            'driver' => 'mariadb',
            'host' => Env::get('DBHOST', 'mysql-mailcow'),
            'port' => Env::get('DBPORT', '3306'),
            'database' => Env::get('DBNAME', 'mailcow'),
            'username' => Env::get('DBUSER', 'mailcow'),
            'password' => Env::get('DBPASS', ''),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            // Query exceptions must never interpolate passwords, tokens or other bound values.
            'mask_bindings_in_exception_messages' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => Env::get('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

    ],

    // Laravel's migrations table carries the API prefix (constitution III).
    'migrations' => [
        'table' => 'restv2_migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => 'phpredis',

        'options' => [
            'cluster' => 'redis',
            'persistent' => false,
        ],

        // API-owned keys: sessions, throttling, TOTP replay markers, configuration cache.
        'restv2' => [
            'host' => $redisHost,
            'port' => $redisPort,
            'password' => Env::get('REDISPASS'),
            'database' => Env::get('REDIS_DB', '0'),
            'prefix' => 'restv2:',
            'read_timeout' => 5,
            'timeout' => 5,
        ],

        // mailcow-owned keys (read only) and the fail2ban channel.
        'mailcow' => [
            'host' => $redisHost,
            'port' => $redisPort,
            'password' => Env::get('REDISPASS'),
            'database' => Env::get('REDIS_DB', '0'),
            'prefix' => '',
            'read_timeout' => 5,
            'timeout' => 5,
        ],

    ],

];
