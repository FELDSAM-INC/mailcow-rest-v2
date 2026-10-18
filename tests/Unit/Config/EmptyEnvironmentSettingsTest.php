<?php

namespace Tests\Unit\Config;

use App\Services\Config\MailcowConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Config\TemporaryEnvironment;
use Tests\TestCase;

/**
 * mailcow's compose override passes optional settings as `NAME=${NAME:-}`, so the container gets
 * them set to the empty string. An empty or whitespace-only setting means unset and resolves to
 * its documented default (docs/conventions.md "Configuration", README). On staging, an empty
 * RESTV2_BULK_LIMIT became 1 and refused every bulk action with two targets.
 *
 * Each case loads the config file again with the variable set to "" and to blanks.
 */
class EmptyEnvironmentSettingsTest extends TestCase
{
    use TemporaryEnvironment;

    private const PROTECTED_CONTAINERS = 'dockerapi-mailcow,mysql-mailcow,redis-mailcow,nginx-mailcow,restv2-mailcow';

    /**
     * config file, variable, config key, documented default
     *
     * @return list<array{string, string, string, mixed}>
     */
    private static function documentedDefaults(): array
    {
        return [
            // config/restv2.php: settings of this API.
            ['restv2', 'RESTV2_SASL_LOG_SERVICE', 'sasl_log_service', 'MAILCOWUI'],
            ['restv2', 'RESTV2_DEFAULT_UI_NAME', 'default_ui_name', ''],
            ['restv2', 'RESTV2_DEFAULT_LOGO_URL', 'default_logo_url', '/img/cow_mailcow.svg'],
            ['restv2', 'RESTV2_DEFAULT_LOGO_DARK_URL', 'default_logo_dark_url', '/img/cow_mailcow.svg'],
            ['restv2', 'RESTV2_SIGNIN_MAX_FAILURES', 'signin_max_failures', 10],
            ['restv2', 'RESTV2_SIGNIN_FAILURE_WINDOW', 'signin_failure_window', 900],
            ['restv2', 'RESTV2_CHALLENGE_TTL', 'challenge_ttl', 300],
            ['restv2', 'RESTV2_AUDIT_RETENTION_DAYS', 'audit_retention_days', 365],
            ['restv2', 'RESTV2_YUBICO_VERIFY_URL', 'yubico_verify_url', 'https://api.yubico.com/wsapi/2.0/verify'],
            ['restv2', 'RESTV2_DOCKERAPI_URL', 'dockerapi.url', 'https://dockerapi:443'],
            ['restv2', 'RESTV2_MEMCACHED_HOST', 'memcached.host', 'memcached'],
            ['restv2', 'RESTV2_MEMCACHED_PORT', 'memcached.port', 11211],
            ['restv2', 'RESTV2_DEFAULT_DKIM_SELECTOR', 'dkim.default_selector', 'dkim'],
            ['restv2', 'RESTV2_DKIM_DNS_PUBLICATION', 'dkim.dns_publication', false],
            ['restv2', 'RESTV2_ISPCONFIG_URL', 'ispconfig.url', ''],
            ['restv2', 'RESTV2_ISPCONFIG_DNS_SERVER_ID', 'ispconfig.dns_server_id', 1],
            ['restv2', 'RESTV2_ISPCONFIG_VERIFY_TLS', 'ispconfig.verify_tls', true],
            ['restv2', 'RESTV2_ISPCONFIG_TIMEOUT', 'ispconfig.timeout', 15],
            ['restv2', 'RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT', 'mailbox_custom_attribute_limit', 100],
            ['restv2', 'RESTV2_BULK_LIMIT', 'bulk_limit', 100],
            ['restv2', 'RESTV2_SYNC_JOBS_CUSTOM_PARAMS_ADMIN_ONLY', 'sync_jobs.custom_params_admin_only', false],
            ['restv2', 'RESTV2_SYNC_JOBS_BULK_LIMIT', 'sync_jobs.bulk_limit', 100],
            ['restv2', 'RESTV2_ROUTING_TEST_SENDER', 'routing.test_sender', 'relay@example.org'],
            ['restv2', 'RESTV2_ROUTING_TEST_RECIPIENT', 'routing.test_recipient', 'null@hosted.mailcow.de'],
            ['restv2', 'RESTV2_ROUTING_TEST_STEP_TIMEOUT', 'routing.test_step_timeout', 15],
            ['restv2', 'RESTV2_ROUTING_TEST_TOTAL_TIMEOUT', 'routing.test_total_timeout', 60],
            ['restv2', 'RESTV2_FAKE_OUTBOUND', 'fake_outbound', false],
            ['restv2', 'RESTV2_SELF_SERVICE', 'monitoring.self_service', 'restv2-mailcow'],
            ['restv2', 'RESTV2_PROTECTED_CONTAINERS', 'monitoring.protected_containers', self::PROTECTED_CONTAINERS],
            ['restv2', 'RESTV2_CONTAINER_ROLES', 'monitoring.container_roles', '{}'],
            ['restv2', 'RESTV2_POSTFIX_CONTAINER', 'monitoring.postfix_container', 'postfix-mailcow'],
            ['restv2', 'RESTV2_DOVECOT_CONTAINER', 'monitoring.dovecot_container', 'dovecot-mailcow'],
            ['restv2', 'RESTV2_BAN_SERVICE_CONTAINER', 'monitoring.ban_service_container', 'netfilter-mailcow'],
            ['restv2', 'RESTV2_PYTHON', 'monitoring.python', 'python3'],
            ['restv2', 'RESTV2_UPDATE_CHECK', 'update_check.enabled', true],
            ['restv2', 'RESTV2_UPDATE_SOURCE_URL', 'update_check.source_url', ''],
            ['restv2', 'RESTV2_UPDATE_STABLE_BRANCH', 'update_check.stable_branch', 'master'],
            ['restv2', 'RESTV2_IP_CHECK_IPV4_URL', 'ip_check.ipv4_url', 'http://ipv4.mailcow.email'],
            ['restv2', 'RESTV2_IP_CHECK_IPV6_URL', 'ip_check.ipv6_url', 'http://ipv6.mailcow.email'],
            ['restv2', 'RESTV2_RSPAMD_SOCKET', 'rspamd.socket', '/var/lib/rspamd/rspamd.sock'],
            ['restv2', 'RESTV2_RSPAMD_TIMEOUT', 'rspamd.timeout', 30],
            ['restv2', 'RESTV2_IDP_TIMEOUT', 'identity_provider.timeout', 7],
            ['restv2', 'RESTV2_IDP_RETRY_AFTER', 'identity_provider.retry_after', 30],
            ['restv2', 'RESTV2_SSO_PENDING_TTL', 'identity_provider.sso_pending_ttl', 600],
            ['restv2', 'RESTV2_SSO_RENEWAL_INTERVAL', 'identity_provider.sso_renewal_interval', 300],
            ['restv2', 'RESTV2_SSO_RETURN_PATH', 'identity_provider.sso_return_path', '/ng/sso/return'],
            ['restv2', 'RESTV2_SSO_RETURN_SCHEME', 'identity_provider.sso_return_scheme', 'https'],
            // config/restv2.php: mailcow.conf names.
            ['restv2', 'MAILCOW_HOSTNAME', 'mailcow_hostname', null],
            ['restv2', 'ADDITIONAL_SERVER_NAMES', 'additional_server_names', ''],
            ['restv2', 'IPV4_NETWORK', 'ipv4_network', '172.22.1'],
            ['restv2', 'IPV6_NETWORK', 'ipv6_network', 'fd4d:6169:6c63:6f77::/64'],
            ['restv2', 'WEBAUTHN_ONLY_TRUSTED_VENDORS', 'webauthn_only_trusted_vendors', false],
            ['restv2', 'SKIP_SOGO', 'skip_sogo', false],
            ['restv2', 'TZ', 'timezone', 'UTC'],
            ['restv2', 'COMPOSE_PROJECT_NAME', 'compose_project_name', null],
            ['restv2', 'MAILCOW_PASS_SCHEME', 'mailcow_pass_scheme', 'BLF-CRYPT'],
            ['restv2', 'MAILDIR_GC_TIME', 'maildir_gc_time', 7200],
            // Empty means "no cluster", which is also the default.
            ['restv2', 'CLUSTERMODE', 'cluster_mode', ''],
            // mailcow's compose file defaults MASTER to y.
            ['restv2', 'MASTER', 'master', true],
            ['restv2', 'SKIP_CLAMD', 'skip_clamd', 'n'],
            ['restv2', 'SKIP_OLEFY', 'skip_olefy', 'n'],
            ['restv2', 'LOG_LINES', 'log_lines', 9999],
            ['restv2', 'HOSTNAME', 'monitoring.hostname', ''],
            // config/app.php
            ['app', 'APP_NAME', 'name', 'mailcow REST API v2'],
            ['app', 'APP_ENV', 'env', 'production'],
            ['app', 'APP_DEBUG', 'debug', false],
            ['app', 'APP_URL', 'url', 'http://localhost'],
            ['app', 'APP_LOCALE', 'locale', 'en'],
            ['app', 'APP_FALLBACK_LOCALE', 'fallback_locale', 'en'],
            ['app', 'APP_FAKER_LOCALE', 'faker_locale', 'en_US'],
            ['app', 'APP_PREVIOUS_KEYS', 'previous_keys', []],
            ['app', 'APP_MAINTENANCE_DRIVER', 'maintenance.driver', 'file'],
            ['app', 'APP_MAINTENANCE_STORE', 'maintenance.store', 'restv2'],
            // config/database.php (mailcow.conf names)
            ['database', 'DBHOST', 'connections.mysql.host', 'mysql-mailcow'],
            ['database', 'DBPORT', 'connections.mysql.port', '3306'],
            ['database', 'DBNAME', 'connections.mysql.database', 'mailcow'],
            ['database', 'DBUSER', 'connections.mysql.username', 'mailcow'],
            ['database', 'DBPASS', 'connections.mysql.password', ''],
            ['database', 'REDISHOST', 'redis.restv2.host', 'redis-mailcow'],
            ['database', 'REDISPASS', 'redis.restv2.password', null],
            ['database', 'REDIS_DB', 'redis.restv2.database', '0'],
            ['database', 'REDIS_SLAVEOF_IP', 'redis.mailcow.port', 6379],
            // config/logging.php, config/session.php, config/filesystems.php
            ['logging', 'LOG_LEVEL', 'channels.stderr_json.level', 'info'],
            ['logging', 'LOG_STREAM', 'channels.stderr_json.handler_with.stream', 'php://stderr'],
            ['session', 'SESSION_COOKIE', 'cookie', '__Host-restv2_session'],
            ['filesystems', 'FILESYSTEM_DISK', 'default', 'local'],
            ['filesystems', 'APP_URL', 'disks.public.url', 'http://localhost/storage'],
        ];
    }

    /**
     * @return array<string, array{string, string, string, mixed, string}>
     */
    public static function emptySettings(): array
    {
        $cases = [];

        foreach (self::documentedDefaults() as [$file, $variable, $key, $default]) {
            $cases["{$file} {$variable} empty"] = [$file, $variable, $key, $default, ''];
            $cases["{$file} {$variable} blank"] = [$file, $variable, $key, $default, " \t "];
        }

        return $cases;
    }

    #[DataProvider('emptySettings')]
    public function test_an_empty_setting_resolves_to_its_documented_default(string $file, string $variable, string $key, mixed $default, string $value): void
    {
        $this->setEnvironment([$variable => $value]);

        $this->assertSame($default, data_get($this->load($file), $key));
    }

    public function test_a_real_value_still_wins(): void
    {
        $this->setEnvironment([
            'RESTV2_BULK_LIMIT' => '7',
            'RESTV2_SYNC_JOBS_BULK_LIMIT' => '9',
            'RESTV2_MAILBOX_CUSTOM_ATTRIBUTE_LIMIT' => '5',
            'RESTV2_CONTAINER_ROLES' => '{"mta-custom":"mailTransfer"}',
            'RESTV2_SASL_LOG_SERVICE' => 'WEBUI',
            'RESTV2_DEFAULT_DKIM_SELECTOR' => 'feldhost',
            'RESTV2_UPDATE_CHECK' => 'n',
            'TZ' => 'Europe/Prague',
            'MASTER' => 'n',
            // Zero is a value, not "empty".
            'MAILDIR_GC_TIME' => '0',
            'CLUSTERMODE' => 'replication',
            'RESTV2_TAGGING_LIMIT' => '30',
            'DBHOST' => 'db.internal',
            'LOG_LEVEL' => 'debug',
            'SESSION_COOKIE' => 'restv2_dev',
        ]);

        $restv2 = $this->load('restv2');
        $this->assertSame(
            [7, 5, '{"mta-custom":"mailTransfer"}', 'WEBUI', 'feldhost', false, 'Europe/Prague', false, 0, 'replication', '30'],
            [
                $restv2['bulk_limit'], $restv2['mailbox_custom_attribute_limit'], $restv2['monitoring']['container_roles'],
                $restv2['sasl_log_service'], $restv2['dkim']['default_selector'], $restv2['update_check']['enabled'],
                $restv2['timezone'], $restv2['master'], $restv2['maildir_gc_time'], $restv2['cluster_mode'],
                $restv2['vars_overrides']['TAGGING_LIMIT'],
            ],
        );
        $this->assertSame(9, $restv2['sync_jobs']['bulk_limit']);
        $this->assertSame('db.internal', data_get($this->load('database'), 'connections.mysql.host'));
        $this->assertSame('debug', data_get($this->load('logging'), 'channels.stderr_json.level'));
        $this->assertSame('restv2_dev', data_get($this->load('session'), 'cookie'));
    }

    public function test_the_update_check_is_turned_off_by_every_documented_spelling(): void
    {
        // Laravel turns "false" into a boolean before the config file sees it.
        foreach (['n', 'NO', 'false', 'FALSE', '0', 'off'] as $value) {
            $this->setEnvironment(['RESTV2_UPDATE_CHECK' => $value]);
            $this->assertFalse($this->load('restv2')['update_check']['enabled'], $value);
        }

        foreach (['y', 'yes', 'true', '1', ''] as $value) {
            $this->setEnvironment(['RESTV2_UPDATE_CHECK' => $value]);
            $this->assertTrue($this->load('restv2')['update_check']['enabled'], $value);
        }
    }

    public function test_an_empty_mount_point_still_means_not_mounted(): void
    {
        // The one setting whose empty value means something: the upstream defaults apply.
        $this->setEnvironment(['RESTV2_MAILCOW_INC_DIR' => '']);
        $this->assertSame('', $this->load('restv2')['mailcow_inc_dir']);

        $this->setEnvironment(['RESTV2_MAILCOW_INC_DIR' => null]);
        $this->assertSame('/mailcow/web/inc', $this->load('restv2')['mailcow_inc_dir']);
    }

    public function test_empty_legacy_variable_settings_leave_the_value_to_mailcows_vars_files(): void
    {
        $names = array_keys((array) config('restv2.vars_overrides'));
        $this->assertNotEmpty($names);

        foreach (['', '  '] as $value) {
            $this->setEnvironment(array_fill_keys(array_map(fn (string $name): string => "RESTV2_{$name}", $names), $value));

            $this->assertSame(array_fill_keys($names, null), $this->load('restv2')['vars_overrides']);
        }
    }

    public function test_true_and_false_legacy_variable_settings_win(): void
    {
        // Laravel turns "true" and "false" into booleans; they are settings, not "unset".
        $this->setEnvironment(['RESTV2_ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true', 'RESTV2_SHOW_LAST_LOGIN' => 'false', 'RESTV2_FIDO2_UV_FLAG_LOGIN' => 'false']);
        config([
            'restv2.vars_overrides' => $this->load('restv2')['vars_overrides'],
            'restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc',
        ]);
        $this->app->forgetInstance(MailcowConfig::class);
        $config = $this->app->make(MailcowConfig::class);

        $this->assertTrue($config->aliasDisableExternalDomains());
        $this->assertFalse($config->showLastLogin());
        $this->assertFalse($config->fido2UvLogin());
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $file): array
    {
        $config = require base_path("config/{$file}.php");
        $this->assertIsArray($config);

        return $config;
    }
}
