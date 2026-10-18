<?php

namespace Tests\Feature\IdentityProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\IdentityProviderTestHelpers;
use Tests\TestCase;

/**
 * T032 (FR-010 to FR-013, US1 AS-5): every rule of a save with its rule code and parameters,
 * reported together; nothing is stored; the refusal is audited.
 */
class SaveIdentityProviderInvalidTest extends TestCase
{
    use IdentityProviderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxTemplate('Staff');
        $this->withoutRequestValidation();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        $k = IdentityProviderFixtures::keycloak(...);
        $o = IdentityProviderFixtures::genericOidc(...);
        $l = IdentityProviderFixtures::ldap(...);

        yield 'keycloak ftp url' => [$k(['serverUrl' => 'ftp://sso.example.org']), 'serverUrl', 'invalidFormat', ['format' => 'httpUrl']];
        yield 'keycloak url too long' => [$k(['serverUrl' => 'https://sso.example.org/'.str_repeat('a', 1980)]), 'serverUrl', 'tooLong', ['max' => 2000]];
        yield 'keycloak realm with slash' => [$k(['realm' => 'a/b']), 'realm', 'invalidFormat', ['format' => 'keycloakRealm']];
        yield 'keycloak realm too long' => [$k(['realm' => str_repeat('r', 256)]), 'realm', 'tooLong', ['max' => 255]];
        yield 'keycloak client id empty' => [$k(['clientId' => '']), 'clientId', 'required', []];
        yield 'keycloak version' => [$k(['version' => 'v26']), 'version', 'invalidFormat', ['format' => 'keycloakVersion']];
        yield 'keycloak sync interval' => [$k(['syncInterval' => 10081]), 'syncInterval', 'outOfRange', ['min' => 1, 'max' => 10080]];
        yield 'keycloak without server url' => [array_diff_key($k(), ['serverUrl' => 1]), 'serverUrl', 'required', []];
        yield 'keycloak secret too long' => [$k(['clientSecret' => str_repeat('s', 1001)]), 'clientSecret', 'tooLong', ['max' => 1000]];
        yield 'legacy return url relative' => [$k(['legacyReturnUrl' => '/']), 'legacyReturnUrl', 'invalidFormat', ['format' => 'httpUrl']];
        yield 'additional return url' => [$k(['additionalReturnUrls' => ['https://ok.example.org', 'mailto:x']]), 'additionalReturnUrls.1', 'invalidFormat', ['format' => 'httpUrl']];
        yield '101 additional return urls' => [$k(['additionalReturnUrls' => array_map(fn (int $i): string => "https://h{$i}.example.org", range(1, 101))]), 'additionalReturnUrls', 'tooMany', ['max' => 100]];
        yield 'oidc endpoint' => [$o(['userInfoEndpoint' => 'idp.example.net/userinfo']), 'userInfoEndpoint', 'invalidFormat', ['format' => 'httpUrl']];
        yield 'oidc scopes double space' => [$o(['scopes' => 'openid  email']), 'scopes', 'invalidFormat', ['format' => 'scopeList']];
        yield 'ldap host' => [$l(['hosts' => ['bad_host.example.org']]), 'hosts.0', 'invalidFormat', ['format' => 'hostName']];
        yield 'ldap 11 hosts' => [$l(['hosts' => array_map(fn (int $i): string => "h{$i}.example.org", range(1, 11))]), 'hosts', 'tooMany', ['max' => 10]];
        yield 'ldap no hosts' => [$l(['hosts' => []]), 'hosts', 'required', []];
        yield 'ldap port' => [$l(['port' => 70000]), 'port', 'outOfRange', ['min' => 1, 'max' => 65535]];
        yield 'ldap base dn too long' => [$l(['baseDn' => str_repeat('d', 1001)]), 'baseDn', 'tooLong', ['max' => 1000]];
        yield 'ldap username field' => [$l(['usernameField' => '1mail']), 'usernameField', 'invalidFormat', ['format' => 'ldapAttribute']];
        yield 'ldap attribute field' => [$l(['attributeField' => 'ti_tle']), 'attributeField', 'invalidFormat', ['format' => 'ldapAttribute']];
        yield 'ldap filter' => [$l(['filter' => '(a=b']), 'filter', 'invalidFormat', ['format' => 'ldapFilter']];
        yield 'ldap attribute field empty with sync' => [$l(['attributeField' => null, 'periodicSync' => true]), 'attributeField', 'conflictsWith', ['field' => 'periodicSync', 'value' => true]];
        yield 'missing template' => [$l(['attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Missing']]]), 'attributeMapping.0.template', 'notFound', ['resource' => 'mailboxTemplate']];
        yield 'missing default template' => [$l(['defaultTemplate' => 'Missing']), 'defaultTemplate', 'notFound', ['resource' => 'mailboxTemplate']];
        yield 'duplicate attribute value' => [$l(['attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Staff'], ['attributeValue' => 'staff', 'template' => 'Staff']]]), 'attributeMapping.1.attributeValue', 'duplicate', ['duplicateOf' => 'attributeMapping.0.attributeValue']];
        yield '101 mapping entries' => [$l(['attributeMapping' => array_map(fn (int $i): array => ['attributeValue' => "v{$i}", 'template' => 'Staff'], range(1, 101))]), 'attributeMapping', 'tooMany', ['max' => 100]];
        yield 'attribute value too long' => [$l(['attributeMapping' => [['attributeValue' => str_repeat('v', 256), 'template' => 'Staff']]]), 'attributeMapping.0.attributeValue', 'tooLong', ['max' => 255]];
        yield 'keycloak member in an ldap body' => [$l(['serverUrl' => 'https://sso.example.org']), 'serverUrl', 'conflictsWith', ['field' => 'type', 'value' => 'ldap']];
        yield 'read-only member' => [$l(['effectivePort' => 636]), 'effectivePort', 'conflictsWith', ['field' => 'type', 'value' => 'ldap']];
        yield 'unknown type' => [['type' => 'saml'], 'type', 'notInList', ['allowed' => ['keycloak', 'genericOidc', 'ldap']]];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_save_bodies_are_refused_with_the_rule_code(array $body, string $field, string $code, array $params): void
    {
        $this->asAdmin();

        $response = $this->putProvider($body)->assertUnprocessable()->assertJsonPath('type', '/api/v2/problems/validation-failed');

        $violation = $response->json('errors.'.str_replace('.', '\.', $field).'.0') ?? ($response->json('errors')[$field][0] ?? null);
        $this->assertIsArray($violation, "no error on {$field}: ".$response->getContent());
        $this->assertSame($code, $violation['code']);
        $this->assertEquals($params, $violation['params']);
        $this->assertSame([], $this->providerRows(), 'nothing is stored');
    }

    public function test_several_invalid_settings_are_reported_together_and_audited(): void
    {
        $this->asAdmin();

        $response = $this->putProvider(IdentityProviderFixtures::keycloak([
            'serverUrl' => 'ftp://x',
            'realm' => 'a/b',
            'version' => 'x',
            'attributeMapping' => [['attributeValue' => 'staff', 'template' => 'Missing']],
        ]))->assertUnprocessable();

        $this->assertEqualsCanonicalizing(['serverUrl', 'realm', 'version', 'attributeMapping.0.template'], array_keys((array) $response->json('errors')));

        $audit = $this->providerAudit('identityProviderCreation');
        $this->assertCount(1, $audit);
        $this->assertSame('failure', $audit[0]->outcome);
        $this->assertSame('validationFailed', $audit[0]->failure_reason);
        $this->assertSame('invalidFormat', $audit[0]->details['errors']['serverUrl'][0]['code']);
    }
}
