<?php

namespace Tests\Feature\OwnAccount;

use App\Auth\AuthMethod;
use App\Auth\Role;
use App\Services\Config\MailcowConfig;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T119 (feature 005 FR-082, US12 AS-1 to AS-3, AS-5): the Apple connection profile of legacy
 * `mobileconfig.php`, with and without an embedded new app password.
 */
class AppleProfileTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/me/mailbox/apple-profile';

    private const FIXTURES = __DIR__.'/../../fixtures/legacy/mobileconfig';

    private const UUID = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['restv2.mailcow_inc_dir' => '/nonexistent/mailcow/web/inc']);
        $this->app->forgetInstance(MailcowConfig::class);
        $this->asSelfServiceUser();
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna & <B>']);
        $this->redis()->set('MAIN_NAME', 'mailcow UI');
    }

    /**
     * The profile as a flat list of element names and texts, payload UUIDs replaced.
     *
     * @return list<string>
     */
    private static function normalized(string $plist): array
    {
        $document = new DOMDocument;
        $document->loadXML($plist);
        $items = [];
        $walk = function (DOMElement $element) use (&$walk, &$items): void {
            $children = array_filter(iterator_to_array($element->childNodes), fn ($n): bool => $n instanceof DOMElement);

            if ($children === []) {
                $text = $element->textContent;
                $items[] = $element->tagName.'='.(preg_match(self::UUID, $text) === 1 || $text === '00000000-0000-4000-8000-000000000000' ? 'UUID' : $text);

                return;
            }

            $items[] = '<'.$element->tagName.'>';

            foreach ($children as $child) {
                $walk($child);
            }

            $items[] = '</'.$element->tagName.'>';
        };
        $walk($document->documentElement ?? throw new \RuntimeException('No plist.'));

        return $items;
    }

    /**
     * Values of a payload dict by key (the first dict with the payload type).
     *
     * @return array<string, string>
     */
    private static function payload(string $plist, string $type): array
    {
        $document = new DOMDocument;
        $document->loadXML($plist);

        foreach ($document->getElementsByTagName('dict') as $dict) {
            $values = [];
            $key = null;

            foreach ($dict->childNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }

                if ($node->tagName === 'key') {
                    $key = $node->textContent;
                } elseif ($key !== null && $node->tagName !== 'array' && $node->tagName !== 'dict') {
                    $values[$key] = in_array($node->tagName, ['true', 'false'], true) ? $node->tagName : $node->textContent;
                    $key = null;
                }
            }

            if (($values['PayloadType'] ?? null) === $type) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertProfileHeaders(TestResponse $response): void
    {
        $response->assertHeader('Content-Type', 'application/x-apple-aspen-config');
        $response->assertHeader('Content-Disposition', 'attachment; filename="mailcow UI.mobileconfig"');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_email_profile_equals_legacys(): void
    {
        $response = $this->get(self::URL.'?variant=email')->assertOk();

        $this->assertProfileHeaders($response);
        $plist = (string) $response->getContent();
        $this->assertSame(self::normalized((string) file_get_contents(self::FIXTURES.'/2026-09a-email.plist')), self::normalized($plist));
        $mail = self::payload($plist, 'com.apple.mail.managed');
        $this->assertSame('Anna & <B>', $mail['EmailAccountName']);
        $this->assertSame('org.example.anna.appleprofile.mailcowUI.email', $mail['PayloadIdentifier']);
        $this->assertMatchesRegularExpression(self::UUID, $mail['PayloadUUID']);
        $this->assertArrayNotHasKey('IncomingPassword', $mail);
        $this->assertStringContainsString('<string>Anna &amp; &lt;B&gt;</string>', $plist);
        $this->assertSame(0, DB::table('app_passwd')->count());
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_the_full_profile_equals_legacys(): void
    {
        $plist = (string) $this->get(self::URL)->assertOk()->getContent();

        $this->assertSame(self::normalized((string) file_get_contents(self::FIXTURES.'/2026-09a-full.plist')), self::normalized($plist));
        $this->assertStringContainsString("<key>CalDAVPort</key>\n        <real>443</real>", $plist);
        $this->assertStringContainsString("<key>CardDAVPort</key>\n        <integer>443</integer>", $plist);
        $this->assertSame('/SOGo/dav/anna@example.org', self::payload($plist, 'com.apple.caldav.account')['CalDAVPrincipalURL']);
    }

    public function test_the_product_name_names_the_file_and_the_identifier(): void
    {
        // legacy stores the name HTML-encoded.
        $this->redis()->set('MAIN_NAME', 'Feld &quot;Host&quot; Mail');

        $response = $this->get(self::URL.'?variant=email')->assertOk();

        $response->assertHeader('Content-Disposition', 'attachment; filename="Feld Host Mail.mobileconfig"');
        $this->assertSame('org.example.anna.appleprofile.FeldHostMail.email', self::payload((string) $response->getContent(), 'com.apple.mail.managed')['PayloadIdentifier']);
    }

    public function test_the_full_profile_needs_the_groupware(): void
    {
        config(['restv2.skip_sogo' => true]);

        $this->get(self::URL)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/groupware-disabled');
        $this->get(self::URL.'?variant=email')->assertOk();
        $this->postEmpty(self::URL, $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/groupware-disabled');
        $this->assertSame(0, DB::table('app_passwd')->count());
    }

    public function test_a_new_app_password_is_embedded(): void
    {
        $response = $this->postJson(self::URL, ['appPasswordName' => 'My iPhone'], $this->csrf())->assertCreated();

        $this->assertProfileHeaders($response);
        $row = DB::table('app_passwd')->where('mailbox', 'anna@example.org')->sole();
        $response->assertHeader('Location', '/api/v2/mailboxes/anna@example.org/app-passwords/'.$row->id);
        $plist = (string) $response->getContent();
        $password = self::payload($plist, 'com.apple.mail.managed')['IncomingPassword'];
        $this->assertSame(32, strlen($password));
        $this->assertSame($password, self::payload($plist, 'com.apple.caldav.account')['CalDAVPassword']);
        $this->assertSame($password, self::payload($plist, 'com.apple.carddav.account')['CardDAVPassword']);
        $this->assertSame('My iPhone', $row->name);
        $this->assertSame([1, 1, 1, 0, 0, 0, 1], [(int) $row->imap_access, (int) $row->smtp_access, (int) $row->dav_access, (int) $row->pop3_access, (int) $row->sieve_access, (int) $row->eas_access, (int) $row->active]);
        $this->assertTrue(password_verify($password, substr((string) $row->password, strlen('{BLF-CRYPT}'))));

        $record = $this->auditRecords('appPasswordCreation');
        $this->assertCount(1, $record);
        $this->assertSame('appleProfile', $record[0]->details['source']);
        $this->assertStringNotContainsString($password, json_encode($record[0]) ?: '');
        $this->assertStringContainsString('with application password', $plist);
    }

    public function test_the_name_follows_the_device(): void
    {
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)';
        $this->withHeader('User-Agent', $iphone);
        $this->signInAs('anna@example.org', Role::MailboxUser, AuthMethod::Password, $iphone);
        $this->postJson(self::URL, ['variant' => 'email'], $this->csrf())->assertCreated();
        $row = DB::table('app_passwd')->sole();
        $this->assertSame('iPhone', $row->name);
        $this->assertSame([1, 1, 0], [(int) $row->imap_access, (int) $row->smtp_access, (int) $row->dav_access]);

        $agent = 'Agent/'.str_repeat('x', 294);
        $this->withHeader('User-Agent', $agent);
        $this->signInAs('anna@example.org', Role::MailboxUser, AuthMethod::Password, $agent);
        $this->postJson(self::URL, ['variant' => 'email'], $this->csrf())->assertCreated();
        $this->assertSame(substr($agent, 0, 255), DB::table('app_passwd')->orderByDesc('id')->value('name'));
    }

    public function test_the_app_password_flag_is_needed(): void
    {
        DB::table('user_acl')->where('username', 'anna@example.org')->update(['app_passwds' => 0]);

        $this->postEmpty(self::URL, $this->csrf())
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['appPasswds']);
        $this->assertSame(0, DB::table('app_passwd')->count());
        $this->get(self::URL.'?variant=email')->assertOk();
    }

    public function test_invalid_variants_are_refused(): void
    {
        $this->withoutRequestValidation();

        $this->get(self::URL.'?variant=other', ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.variant.0.code', 'notInList');
        $this->postJson(self::URL, ['variant' => 'other', 'appPasswordName' => ''], $this->csrf())->assertStatus(422)->assertJsonPath('errors.variant.0.code', 'notInList');
    }

    public function test_other_callers_are_refused(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);
        $this->get(self::URL)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->signInAs('admin', Role::Admin);
        $this->get(self::URL)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->postEmpty(self::URL, $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->get(self::URL, $this->keyHeader($this->apiKey('admin', 'readWrite')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->assertSame(0, DB::table('app_passwd')->count());
    }
}
