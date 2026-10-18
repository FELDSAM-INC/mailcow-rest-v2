<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T029 (FR-001, FR-012–FR-016, US1): listMailboxes with paging, filters, search and
 * sorting, scoped to the caller, each entry with its full representation and version tag.
 */
class ListMailboxesTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    /**
     * @return list<string>
     */
    private function addresses(string $query = '', array $headers = []): array
    {
        $response = $this->getJson('/api/v2/mailboxes'.$query, $headers)->assertOk();

        return array_column((array) $response->json('data'), 'address');
    }

    public function test_the_first_page_is_25_mailboxes_by_address_without_resources(): void
    {
        $expected = [];

        foreach (['a.example', 'b.example', 'c.example'] as $domain) {
            $this->mailcow()->domain($domain);

            for ($i = 1; $i <= 10; $i++) {
                $address = sprintf('user%02d@%s', $i, $domain);
                $this->mailcow()->legacyMailbox($address);
                $expected[] = $address;
            }
        }

        $this->mailcow()->mailbox('room@a.example', kind: 'location');
        $this->mailcow()->mailbox('beamer@b.example', kind: 'thing');
        sort($expected);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mailboxes')->assertOk();

        $this->assertSame(array_slice($expected, 0, 25), array_column((array) $response->json('data'), 'address'));
        $this->assertSame(['total' => 30, 'limit' => 25, 'offset' => 0], $response->json('meta'));
        $this->assertSame(array_slice($expected, 28), $this->addresses('?offset=28'));
        $this->assertSame(array_slice($expected, 5, 3), $this->addresses('?limit=3&offset=5'));
    }

    public function test_invalid_list_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->getJson('/api/v2/mailboxes?limit=1001')->assertStatus(422)->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/mailboxes?sort=bogus')->assertStatus(422)->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->assertSame('notInList', $this->getJson('/api/v2/mailboxes?status=frozen')->assertStatus(422)->json('errors')['status.0'][0]['code'] ?? null);
        $this->assertSame('notInList', $this->getJson('/api/v2/mailboxes?authSource=kerberos')->assertStatus(422)->json('errors')['authSource.0'][0]['code'] ?? null);
    }

    public function test_domain_administrators_see_only_their_domains(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->mailcow()->legacyMailbox('bob@b.example');
        $this->mailcow()->legacyMailbox('carl@xn--bcher-kva.example');
        $this->asDomainAdmin(['a.example', 'xn--bcher-kva.example']);

        $this->assertSame(['anna@a.example', 'carl@xn--bcher-kva.example'], $this->addresses());
        $response = $this->getJson('/api/v2/mailboxes?domain=b.example')->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame(['carl@xn--bcher-kva.example'], $this->addresses('?domain='.rawurlencode('BÜCHER.example')));
        $this->assertSame(['anna@a.example', 'carl@xn--bcher-kva.example'], $this->addresses('?domain=a.example&domain=xn--bcher-kva.example'));
    }

    public function test_the_search_matches_addresses_display_names_and_tags(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->mailcow()->legacyMailbox('bob@a.example', name: 'Joanna Bob');
        $this->mailcow()->legacyMailbox('carl@a.example', tags: ['Annapolis']);
        $this->mailcow()->legacyMailbox('dave@a.example', tags: ['Sales']);
        $this->asAdmin();

        $this->assertSame(['anna@a.example', 'bob@a.example', 'carl@a.example'], $this->addresses('?q=ANNA'));
        $this->assertSame(['dave@a.example'], $this->addresses('?q=ale'));
        $this->assertSame([], $this->addresses('?q='.rawurlencode('100%')));
    }

    public function test_filters_combine_with_each_other_and_the_search(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->legacyMailbox('active@a.example', tags: ['Sales']);
        $this->mailcow()->legacyMailbox('disabled@a.example', active: 2, tags: ['sales']);
        $this->mailcow()->legacyMailbox('inactive@b.example', active: 0, tags: ['Salesforce']);
        $this->mailcow()->legacyMailbox('ldap@b.example', authsource: 'ldap', tags: ['VIP']);
        $this->mailcow()->legacyMailbox('oidc@b.example', authsource: 'generic-oidc');
        DB::table('mailbox')->where('username', 'active@a.example')->update(['authsource' => null]);
        $this->asAdmin();

        $this->assertSame(['active@a.example', 'ldap@b.example', 'oidc@b.example'], $this->addresses('?status=active'));
        $this->assertSame(['disabled@a.example', 'inactive@b.example'], $this->addresses('?status=loginDisabled&status=inactive'));
        $this->assertSame(['active@a.example', 'disabled@a.example'], $this->addresses('?tag=SALES'));
        $this->assertSame(['active@a.example', 'disabled@a.example', 'ldap@b.example'], $this->addresses('?tag=sales&tag=vip'));
        $this->assertSame(['active@a.example', 'disabled@a.example', 'inactive@b.example'], $this->addresses('?authSource=mailcow'));
        $this->assertSame(['ldap@b.example', 'oidc@b.example'], $this->addresses('?authSource=ldap&authSource=genericOidc'));
        $this->assertSame(['disabled@a.example'], $this->addresses('?tag=sales&status=loginDisabled'));
        $this->assertSame(['inactive@b.example'], $this->addresses('?domain=b.example&q=force'));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function sorts(): array
    {
        return [
            'address' => ['address', ['a@s.example', 'b@s.example', 'c@s.example', 'd@t.example']],
            'displayName' => ['displayName', ['b@s.example', 'c@s.example', 'a@s.example', 'd@t.example']],
            'domain' => ['domain', ['a@s.example', 'b@s.example', 'c@s.example', 'd@t.example']],
            'status' => ['status', ['c@s.example', 'a@s.example', 'd@t.example', 'b@s.example']],
            'quota' => ['quota', ['a@s.example', 'b@s.example', 'd@t.example', 'c@s.example']],
            'quotaUsed' => ['quotaUsed', ['d@t.example', 'c@s.example', 'b@s.example', 'a@s.example']],
            'messageCount' => ['messageCount', ['d@t.example', 'a@s.example', 'b@s.example', 'c@s.example']],
            'createdAt' => ['createdAt', ['c@s.example', 'b@s.example', 'a@s.example', 'd@t.example']],
        ];
    }

    private function seedForSorting(): void
    {
        $this->mailcow()->domain('s.example');
        $this->mailcow()->domain('t.example');
        // address, name, status, quota (MiB), bytes, messages, created
        $rows = [
            ['a@s.example', 'Zed', 1, 0, 3000, 1, '2026-01-03 10:00:00'],
            ['b@s.example', 'Anna', 2, 100, 2000, 2, '2026-01-02 10:00:00'],
            ['c@s.example', 'Bea', 0, 300, 1000, 3, '2026-01-01 10:00:00'],
            ['d@t.example', 'Zed', 1, 200, 0, 0, '2026-01-04 10:00:00'],
        ];

        foreach ($rows as [$address, $name, $active, $quota, $bytes, $messages, $created]) {
            $this->mailcow()->legacyMailbox($address, active: $active, quota: $quota * 1048576, name: $name);
            $this->mailcow()->usage($address, $bytes, $messages);
            DB::table('mailbox')->where('username', $address)->update(['created' => $created]);
        }
    }

    /**
     * @param  list<string>  $ascending
     */
    #[DataProvider('sorts')]
    public function test_every_sort_field_in_both_orders_with_ties_by_address(string $sort, array $ascending): void
    {
        $this->seedForSorting();
        $this->asAdmin();

        $this->assertSame($ascending, $this->addresses("?sort={$sort}"));

        $descending = array_reverse($ascending);

        if ($sort === 'displayName') {
            $descending = ['a@s.example', 'd@t.example', 'c@s.example', 'b@s.example'];
        } elseif ($sort === 'domain') {
            $descending = ['d@t.example', 'a@s.example', 'b@s.example', 'c@s.example'];
        } elseif ($sort === 'status') {
            $descending = ['b@s.example', 'a@s.example', 'd@t.example', 'c@s.example'];
        }

        $this->assertSame($descending, $this->addresses("?sort={$sort}&order=desc"));
    }

    public function test_missing_values_come_last_in_both_orders(): void
    {
        $this->seedForSorting();
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_REMOVE(`attributes`, '$.passwd_update') WHERE `username` = ?", ['a@s.example']);
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', ?) WHERE `username` = ?", [(string) strtotime('2025-06-01 12:00:00 UTC'), 'b@s.example']);
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', '2025-07-01 12:00:00') WHERE `username` = ?", ['c@s.example']);
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', '2025-05-01 12:00:00') WHERE `username` = ?", ['d@t.example']);
        DB::table('mailbox')->whereIn('username', ['a@s.example', 'd@t.example'])->update(['modified' => null]);
        DB::table('mailbox')->where('username', 'b@s.example')->update(['modified' => '2026-02-01 10:00:00']);
        DB::table('mailbox')->where('username', 'c@s.example')->update(['modified' => '2026-03-01 10:00:00']);
        $this->mailcow()->saslLog('b@s.example', 'imap', '2026-05-01 10:00:00');
        $this->mailcow()->saslLog('b@s.example', 'SSO', '2026-09-01 10:00:00');
        $this->mailcow()->saslLog('c@s.example', 'smtp', '2026-06-01 10:00:00');
        $this->mailcow()->saslLog('d@t.example', 'MAILCOWUI', '2026-10-01 10:00:00');
        $this->asAdmin();

        // Unlimited quota (a) has no percentage: b 2000/100 MiB, c 1000/300 MiB, d 0/200 MiB.
        $this->assertSame(['d@t.example', 'c@s.example', 'b@s.example', 'a@s.example'], $this->addresses('?sort=percentUsed'));
        $this->assertSame(['b@s.example', 'c@s.example', 'd@t.example', 'a@s.example'], $this->addresses('?sort=percentUsed&order=desc'));
        $this->assertSame(['b@s.example', 'c@s.example', 'a@s.example', 'd@t.example'], $this->addresses('?sort=lastMailLogin'));
        $this->assertSame(['c@s.example', 'b@s.example', 'a@s.example', 'd@t.example'], $this->addresses('?sort=lastMailLogin&order=desc'));
        $this->assertSame(['d@t.example', 'b@s.example', 'c@s.example', 'a@s.example'], $this->addresses('?sort=passwordChangedAt'));
        $this->assertSame(['c@s.example', 'b@s.example', 'd@t.example', 'a@s.example'], $this->addresses('?sort=passwordChangedAt&order=desc'));
        $this->assertSame(['b@s.example', 'c@s.example', 'a@s.example', 'd@t.example'], $this->addresses('?sort=modifiedAt'));
        $this->assertSame(['c@s.example', 'b@s.example', 'a@s.example', 'd@t.example'], $this->addresses('?sort=modifiedAt&order=desc'));
    }

    public function test_each_entry_is_the_full_representation_with_the_version_tag_of_the_single_read(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->legacyMailbox('anna@a.example', tags: ['Sales'], quota: 1073741824);
        $this->mailcow()->legacyMailbox('bob@a.example', active: 2);
        $this->asAdmin();

        foreach ((array) $this->getJson('/api/v2/mailboxes')->assertOk()->json('data') as $entry) {
            $single = $this->getJson('/api/v2/mailboxes/'.$entry['address'])->assertOk();
            $this->assertSame($single->headers->get('ETag'), $entry['etag']);
            $this->assertSame($single->json('data'), $entry);
        }
    }

    public function test_last_logins_are_hidden_when_the_operator_turned_them_off(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->mailcow()->saslLog('anna@a.example', 'imap', '2026-10-01 10:00:00');
        config(['restv2.vars_overrides.SHOW_LAST_LOGIN' => 'n']);
        $this->asAdmin();

        $this->assertSame(['imap' => null, 'pop3' => null, 'smtp' => null, 'webmailSso' => null], $this->getJson('/api/v2/mailboxes')->assertOk()->json('data.0.lastLogins'));
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->asAdmin();
        $this->breakSharedStore();

        $this->getJson('/api/v2/mailboxes')->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'sharedStore');
    }

    public function test_keys_list_their_owners_scope(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->legacyMailbox('anna@a.example');
        $this->mailcow()->legacyMailbox('bob@b.example');
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->mailcow()->domainAdmin('reseller', ['b.example']);

        $this->assertSame(['anna@a.example', 'bob@b.example'], $this->addresses('', $this->keyHeader($this->apiKey('admin', 'readOnly'))));
        $this->assertSame(['anna@a.example', 'bob@b.example'], $this->addresses('', $this->keyHeader($this->apiKey('admin', 'readWrite', name: 'rw'))));
        $this->assertSame(['bob@b.example'], $this->addresses('', $this->keyHeader($this->apiKey('reseller', 'readOnly', name: 'da'))));
    }

    public function test_mailbox_users_restricted_sessions_and_anonymous_callers_are_refused(): void
    {
        $this->asMailboxUser('anna@a.example');
        $this->getJson('/api/v2/mailboxes')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('restricted', MailcowFactory::PASSWORD, attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);
        $this->getJson('/api/v2/mailboxes')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->forgetCookies();
        $this->getJson('/api/v2/mailboxes')->assertStatus(401);
    }
}
