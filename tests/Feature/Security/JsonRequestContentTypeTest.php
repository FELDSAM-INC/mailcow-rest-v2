<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

class JsonRequestContentTypeTest extends TestCase
{
    use MailboxTestHelpers;

    /** @return array<string, array{string|null}> */
    public static function unsupportedContentTypes(): array
    {
        return [
            'plain text' => ['text/plain'],
            'form' => ['application/x-www-form-urlencoded'],
            'binary' => ['application/octet-stream'],
            'missing header' => [null],
        ];
    }

    #[DataProvider('unsupportedContentTypes')]
    public function test_invalid_content_type_cannot_change_optional_mailbox_fields(?string $contentType): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', quota: self::GIB, attributes: ['attribute_hash' => 'unchanged']);
        $this->asAdmin();
        $before = (array) $this->mailboxRow('anna@example.org');
        $domainBefore = (array) DB::table('domain')->where('domain', 'example.org')->first();
        $headers = ['Accept' => 'application/json', ...$this->csrf()];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $this->withoutRequestValidation()->call(
            'PATCH',
            '/api/v2/mailboxes/anna@example.org',
            server: $this->transformHeadersToServerVars($headers),
            content: json_encode(['quota' => 2 * self::GIB, 'displayName' => 'Changed'], JSON_THROW_ON_ERROR),
        )->assertStatus(415)->assertJsonPath('type', 'about:blank')->assertJsonPath('status', 415);

        $this->assertSame($before, (array) $this->mailboxRow('anna@example.org'));
        $this->assertSame($domainBefore, (array) DB::table('domain')->where('domain', 'example.org')->first());
        $audit = $this->auditOf('mailboxUpdate');
        $this->assertCount(1, $audit);
        $this->assertSame('failure', $audit[0]->outcome);
        $this->assertSame(['problemType' => 'about:blank', 'status' => 415], $audit[0]->details);
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_the_same_optional_fields_with_merge_patch_content_type_are_accepted(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', quota: self::GIB);
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['quota' => 2 * self::GIB, 'displayName' => 'Changed'])
            ->assertOk()->assertJsonPath('data.quota', 2 * self::GIB)->assertJsonPath('data.displayName', 'Changed');

        $this->assertSame(2 * self::GIB, (int) $this->mailboxRow('anna@example.org')?->quota);
        $this->assertSame(1, $this->memcached()->flushCount());
    }
}
