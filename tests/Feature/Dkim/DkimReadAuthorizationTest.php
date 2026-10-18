<?php

namespace Tests\Feature\Dkim;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T037 (US1 AS3, AS4, AS6, FR-001 to FR-005, FR-002): the role and key matrix of the
 * DKIM reads and the capabilities. Read-only keys may read; mailbox users are forbidden; a domain
 * administrator reaches exactly their domains and alias domains, and a foreign name or an unused
 * key answers the same `404` as a name that does not exist.
 */
class DkimReadAuthorizationTest extends TestCase
{
    use DkimTestHelpers;

    private const CALLERS = ['anonymous', 'restrictedSession', 'adminSession', 'adminKey', 'adminReadOnlyKey', 'mailboxSession', 'daSession', 'daKey', 'daReadOnlyKey'];

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('alias.example', 'example.org');
        $f->dkimKey('example.org');
        $f->dkimKey('foreign.example');
        $f->dkimKey('unused.example');
        $f->admin('admin', 'Passw0rd!');
        $f->admin('restricted', 'Passw0rd!', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org']);
        $f->mailboxWithDomain('anna@example.org');
        $this->keys = [
            'adminKey' => $this->apiKey('admin')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'daKey' => $this->apiKey('reseller')['secret'],
            'daReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (['listDkimKeys' => '/api/v2/dkim-keys', 'getDkimKey' => '/api/v2/dkim-keys/example.org', 'getDnsCapabilities' => '/api/v2/capabilities/dns'] as $operation => $path) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$path, $caller];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_every_read_for_every_caller(string $path, string $caller): void
    {
        [$status, $type] = match ($caller) {
            'anonymous' => [401, 'unauthenticated'],
            'restrictedSession' => [403, 'pending-action-required'],
            'mailboxSession' => [403, 'forbidden'],
            default => [200, null],
        };

        $response = $this->getAs($caller, $path)->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    public function test_a_domain_administrator_lists_exactly_their_domain_and_alias_domain(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->getJson('/api/v2/dkim-keys')->assertOk();

        $this->assertSame(['alias.example', 'example.org'], array_column((array) $response->json('data'), 'name'));
        $response->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v2/dkim-keys?state=unused')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v2/dkim-keys/alias.example')->assertOk()->assertJsonPath('data.state', 'missing');
    }

    public function test_foreign_and_unused_names_answer_like_a_name_that_does_not_exist(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $missing = $this->getJson('/api/v2/dkim-keys/nothing.example')->assertNotFound()->json();
        $foreign = $this->getJson('/api/v2/dkim-keys/foreign.example')->assertNotFound()->json();
        $unused = $this->getJson('/api/v2/dkim-keys/unused.example')->assertNotFound()->json();

        unset($missing['requestId'], $foreign['requestId'], $unused['requestId']);
        $this->assertSame($missing, $foreign);
        $this->assertSame($missing, $unused);
    }

    public function test_a_removed_assignment_answers_not_found_on_the_next_request(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson('/api/v2/dkim-keys/example.org')->assertOk();

        DB::table('domain_admins')->where('username', 'reseller')->delete();

        $this->getJson('/api/v2/dkim-keys/example.org')->assertNotFound();
        $this->getJson('/api/v2/dkim-keys')->assertOk()->assertJsonPath('meta.total', 0);
    }

    /**
     * @return TestResponse<Response>
     */
    private function getAs(string $caller, string $path): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'restrictedSession' => $this->signInAs('restricted', Role::Admin),
            'mailboxSession' => $this->signInAs('anna@example.org', Role::MailboxUser),
            'daSession' => $this->signInAs('reseller', Role::DomainAdmin),
            default => $headers = $this->keyHeader($this->keys[$caller]),
        };

        return $this->getJson($path, $headers);
    }
}
