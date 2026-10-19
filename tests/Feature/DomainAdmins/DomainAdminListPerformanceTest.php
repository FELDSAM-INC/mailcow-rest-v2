<?php

namespace Tests\Feature\DomainAdmins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Feature 007 T092 (SC-008, research R14): with 5,000 domains and 5,000 domain administrators
 * (one to three assignments each, permission records and some second factors and passkeys), a page
 * of 25 with its domains and flags is answered within 1 s in 95% of 50 requests with varying
 * offsets, sorts (domainCount included) and searches. A timing test: `composer test:performance`.
 */
#[Group('performance')]
class DomainAdminListPerformanceTest extends TestCase
{
    public function test_a_page_of_25_within_one_second(): void
    {
        $domains = [];

        for ($i = 0; $i < 5000; $i++) {
            $domains[] = ['domain' => sprintf('d%04d.example', $i), 'description' => '', 'aliases' => 400, 'mailboxes' => 10, 'defquota' => 1024, 'maxquota' => 10240, 'quota' => 10240,
                'backupmx' => 0, 'gal' => 1, 'active' => 1, 'relay_unknown_only' => 0, 'relay_all_recipients' => 0];
        }

        foreach (array_chunk($domains, 1000) as $chunk) {
            DB::table('domain')->insert($chunk);
        }

        $admins = [];
        $assignments = [];
        $acls = [];
        $tfa = [];
        $fido2 = [];

        for ($i = 0; $i < 5000; $i++) {
            $username = sprintf('reseller%04d', $i);
            $admins[] = ['username' => $username, 'password' => '{PLAIN}x', 'superadmin' => 0, 'active' => $i % 7 === 0 ? 0 : 1, 'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}'];
            $acls[] = ['username' => $username, 'login_as' => $i % 2];

            for ($j = 0; $j <= $i % 3; $j++) {
                $assignments[] = ['username' => $username, 'domain' => sprintf('d%04d.example', ($i + $j * 1700) % 5000), 'active' => 1];
            }

            if ($i % 5 === 0) {
                $tfa[] = ['username' => $username, 'key_id' => 'Phone', 'authmech' => 'totp', 'secret' => 'S', 'active' => $i % 10 === 0 ? 1 : 0];
            }

            if ($i % 4 === 0) {
                $fido2[] = ['username' => $username, 'rpId' => 'mail.example.org', 'credentialPublicKey' => 'PEM', 'credentialId' => random_bytes(16), 'active' => 1];
            }
        }

        foreach (['admin' => $admins, 'domain_admins' => $assignments, 'da_acl' => $acls, 'tfa' => $tfa, 'fido2' => $fido2] as $table => $rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        DB::table('admin')->insert(['username' => 'admin', 'password' => '{PLAIN}x', 'superadmin' => 1, 'active' => 1, 'attributes' => '{"force_tfa": "0", "force_pw_update": "0"}']);
        $this->signInAs('admin', Role::Admin);
        $queries = [];

        foreach (range(0, 49) as $n) {
            $sort = ['username', 'active', 'createdAt', 'modifiedAt', 'domainCount'][$n % 5];
            $order = $n % 2 === 0 ? 'asc' : 'desc';
            $search = $n % 10 === 3 ? '&q=reseller12' : ($n % 10 === 7 ? '&q=d12' : '');
            $queries[] = '/api/v2/domain-admins?limit=25&offset='.(($n * 97) % 4975)."&sort={$sort}&order={$order}{$search}";
        }

        $timings = [];

        foreach ($queries as $query) {
            $started = microtime(true);
            $this->getJson($query)->assertOk();
            $timings[] = microtime(true) - $started;
        }

        sort($timings);
        $p95 = $timings[(int) ceil(0.95 * count($timings)) - 1];
        $this->assertLessThan(1.0, $p95, sprintf('p95 %.3f s', $p95));
    }
}
