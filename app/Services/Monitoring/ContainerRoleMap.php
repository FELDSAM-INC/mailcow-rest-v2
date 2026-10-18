<?php

namespace App\Services\Monitoring;

/**
 * The stable role of a container (contract `ContainerRole`, feature 011 research R3), looked up by
 * its compose service name: a table, not inference, so that it stays stable across releases. This
 * API's own container is `api`; unknown services are `other`. The server setting
 * `RESTV2_CONTAINER_ROLES` (a JSON map) adds or overrides entries for renamed services.
 */
class ContainerRoleMap
{
    public const ROLES = [
        'dnsResolver', 'database', 'keyValueStore', 'antivirus', 'spamFilter', 'legacyWebApp', 'webmail',
        'imap', 'mailTransfer', 'tlsPolicy', 'cache', 'proxy', 'certificates', 'banService', 'healthMonitor',
        'containerService', 'macroScanner', 'scheduler', 'api', 'webApp', 'other',
    ];

    private const UPSTREAM = [
        'unbound-mailcow' => 'dnsResolver',
        'mysql-mailcow' => 'database',
        'redis-mailcow' => 'keyValueStore',
        'clamd-mailcow' => 'antivirus',
        'rspamd-mailcow' => 'spamFilter',
        'php-fpm-mailcow' => 'legacyWebApp',
        'sogo-mailcow' => 'webmail',
        'dovecot-mailcow' => 'imap',
        'postfix-mailcow' => 'mailTransfer',
        'postfix-tlspol-mailcow' => 'tlsPolicy',
        'memcached-mailcow' => 'cache',
        'nginx-mailcow' => 'proxy',
        'acme-mailcow' => 'certificates',
        'netfilter-mailcow' => 'banService',
        'watchdog-mailcow' => 'healthMonitor',
        'dockerapi-mailcow' => 'containerService',
        'olefy-mailcow' => 'macroScanner',
        'ofelia-mailcow' => 'scheduler',
        'ng-mailcow' => 'webApp',
        'restv2-mailcow' => 'api',
    ];

    public function role(string $service, bool $own = false): string
    {
        $overrides = $this->overrides();

        return $overrides[$service] ?? ($own ? 'api' : (self::UPSTREAM[$service] ?? 'other'));
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        $decoded = json_decode((string) config('restv2.monitoring.container_roles', '{}'), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        return array_filter(
            array_filter($decoded, 'is_string'),
            fn (string $role): bool => in_array($role, self::ROLES, true),
        );
    }
}
