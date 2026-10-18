<?php

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\ContainerRoleMap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 011 T011 (research R3, FR-008): every container carries a stable role derived from its
 * service name, so that clients need not know mailcow's service names.
 */
class ContainerRoleMapTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function upstreamServices(): array
    {
        return [
            'unbound' => ['unbound-mailcow', 'dnsResolver'],
            'mysql' => ['mysql-mailcow', 'database'],
            'redis' => ['redis-mailcow', 'keyValueStore'],
            'clamd' => ['clamd-mailcow', 'antivirus'],
            'rspamd' => ['rspamd-mailcow', 'spamFilter'],
            'php-fpm' => ['php-fpm-mailcow', 'legacyWebApp'],
            'sogo' => ['sogo-mailcow', 'webmail'],
            'dovecot' => ['dovecot-mailcow', 'imap'],
            'postfix' => ['postfix-mailcow', 'mailTransfer'],
            'postfix-tlspol' => ['postfix-tlspol-mailcow', 'tlsPolicy'],
            'memcached' => ['memcached-mailcow', 'cache'],
            'nginx' => ['nginx-mailcow', 'proxy'],
            'acme' => ['acme-mailcow', 'certificates'],
            'netfilter' => ['netfilter-mailcow', 'banService'],
            'watchdog' => ['watchdog-mailcow', 'healthMonitor'],
            'dockerapi' => ['dockerapi-mailcow', 'containerService'],
            'olefy' => ['olefy-mailcow', 'macroScanner'],
            'ofelia' => ['ofelia-mailcow', 'scheduler'],
            'ng' => ['ng-mailcow', 'webApp'],
            'restv2' => ['restv2-mailcow', 'api'],
            'unknown' => ['foo-mailcow', 'other'],
        ];
    }

    #[DataProvider('upstreamServices')]
    public function test_every_upstream_service_maps_to_its_role(string $service, string $role): void
    {
        $this->assertSame($role, (new ContainerRoleMap)->role($service));
    }

    public function test_the_own_container_is_the_api_whatever_its_name(): void
    {
        $this->assertSame('api', (new ContainerRoleMap)->role('mailcow-api-custom', own: true));
    }

    public function test_the_setting_adds_and_overrides_roles(): void
    {
        config(['restv2.monitoring.container_roles' => '{"mta-custom":"mailTransfer","sogo-mailcow":"other","bad":"notARole"}']);
        $map = new ContainerRoleMap;

        $this->assertSame('mailTransfer', $map->role('mta-custom'));
        $this->assertSame('other', $map->role('sogo-mailcow'));
        $this->assertSame('other', $map->role('bad'), 'an unknown role value is ignored');
    }

    public function test_an_invalid_or_empty_setting_is_ignored(): void
    {
        foreach (['', 'not json', '[1,2]'] as $value) {
            config(['restv2.monitoring.container_roles' => $value]);
            $this->assertSame('database', (new ContainerRoleMap)->role('mysql-mailcow'));
        }
    }
}
