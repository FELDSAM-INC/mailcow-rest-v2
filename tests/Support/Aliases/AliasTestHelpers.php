<?php

namespace Tests\Support\Aliases;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;

/**
 * Callers, rows, requests and lookups shared by the tests of feature 004. Used in Tests\TestCase
 * subclasses.
 */
trait AliasTestHelpers
{
    use MailboxTestHelpers;

    /**
     * A mailbox as legacy creates it (with its own-alias row), hashed cheaply.
     */
    protected function mailbox(string $address, int $active = 1, ?string $name = null): void
    {
        $this->mailcow()->ensureDomain(substr((string) strrchr($address, '@'), 1));
        $this->mailcow()->legacyMailbox($address, active: $active, name: $name, scheme: 'SSHA256');
    }

    /**
     * @phpstan-impure
     */
    protected function aliasRow(int|string $idOrAddress): ?stdClass
    {
        $query = DB::table('alias');
        $row = is_int($idOrAddress) ? $query->where('id', $idOrAddress)->first() : $query->where('address', $idOrAddress)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @phpstan-impure
     */
    protected function viewRow(string $username): ?stdClass
    {
        $row = DB::table('_sogo_static_view')->where('c_uid', $username)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The sender_acl rows as `logged_in_as send_as [external]`, sorted.
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    protected function senderAcls(): array
    {
        $rows = DB::table('sender_acl')->get()->map(fn (stdClass $r): string => $r->logged_in_as.' '.$r->send_as.((int) $r->external === 1 ? ' external' : ''))->all();
        sort($rows);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function createAlias(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/aliases', $body, $headers + $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchAlias(int $id, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/aliases/'.$id, $patch, $headers + $this->csrf());
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteAlias(int $id, array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/aliases/'.$id, [], $headers + $this->csrf());
    }

    /**
     * @return list<string>
     */
    protected function addressesOf(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'address');
    }
}
