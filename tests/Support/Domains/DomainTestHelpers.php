<?php

namespace Tests\Support\Domains;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use stdClass;
use Tests\Support\Mailcow\MailcowFactory;

/**
 * Callers, rows and lookups shared by the tests of feature 002. Used in Tests\TestCase subclasses.
 */
trait DomainTestHelpers
{
    protected const MIB = 1048576;

    protected const GIB = 1073741824;

    protected function asAdmin(string $username = 'admin'): void
    {
        if (! DB::table('admin')->where('username', $username)->exists()) {
            $this->mailcow()->admin($username, MailcowFactory::PASSWORD);
        }

        $this->signInAs($username, Role::Admin);
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, int|bool>  $daAcl
     */
    protected function asDomainAdmin(array $domains, string $username = 'reseller', array $daAcl = []): void
    {
        if (! DB::table('admin')->where('username', $username)->exists()) {
            $this->mailcow()->domainAdmin($username, $domains, $daAcl);
        }

        $this->signInAs($username, Role::DomainAdmin);
    }

    protected function asMailboxUser(string $username = 'user@example.org'): void
    {
        if (! DB::table('mailbox')->where('username', $username)->exists()) {
            $this->mailcow()->mailboxWithDomain($username);
        }

        $this->signInAs($username, Role::MailboxUser);
    }

    /**
     * The headers of a state-changing session request.
     *
     * @return array<string, string>
     */
    protected function csrf(): array
    {
        return ['X-CSRF-Token' => $this->csrfToken()];
    }

    protected function redis(): \Redis
    {
        $client = Redis::connection('mailcow')->client();
        $this->assertInstanceOf(\Redis::class, $client);

        return $client;
    }

    protected function domainRow(string $name): ?stdClass
    {
        $row = DB::table('domain')->where('domain', $name)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @return list<string>
     */
    protected function tagsOf(string $domain): array
    {
        return DB::table('tags_domain')->where('domain', $domain)->orderBy('tag_name')->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();
    }

    /**
     * @return list<stdClass>
     */
    protected function auditOf(string $eventType): array
    {
        return DB::table('restv2_audit_events')->where('event_type', $eventType)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all();
    }
}
