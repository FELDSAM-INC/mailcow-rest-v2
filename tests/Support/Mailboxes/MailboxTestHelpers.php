<?php

namespace Tests\Support\Mailboxes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;

/**
 * Callers, rows and lookups shared by the tests of feature 003. Used in Tests\TestCase subclasses.
 */
trait MailboxTestHelpers
{
    use DomainTestHelpers;

    /**
     * @phpstan-impure
     */
    protected function mailboxRow(string $address): ?stdClass
    {
        $row = DB::table('mailbox')->where('username', $address)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The decoded `attributes` of a mailbox.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    protected function mailboxAttributes(string $address): array
    {
        return (array) json_decode((string) ($this->mailboxRow($address)->attributes ?? '{}'), true);
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    protected function mailboxTags(string $address): array
    {
        return DB::table('tags_mailbox')->where('username', $address)->orderBy('tag_name')->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();
    }

    /**
     * Points the `mailcow` Redis connection at a closed port: mailcow's shared store is down.
     */
    protected function breakSharedStore(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
    }

    /**
     * Creates a mailbox through the API as the signed-in session.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function createMailbox(array $body): TestResponse
    {
        return $this->postJson('/api/v2/mailboxes', $body, $this->csrf());
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchMailbox(string $address, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/mailboxes/'.$address, $patch, $this->csrf() + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteMailbox(string $address, array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/mailboxes/'.$address, [], $this->csrf() + $headers);
    }

    /**
     * The audit records of one target, oldest first.
     *
     * @return list<stdClass>
     *
     * @phpstan-impure
     */
    protected function auditOfTarget(string $type, string $id): array
    {
        return DB::table('restv2_audit_events')->where('target_type', $type)->where('target_id', $id)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->all();
    }
}
