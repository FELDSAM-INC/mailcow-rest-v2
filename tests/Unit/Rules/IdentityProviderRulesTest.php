<?php

namespace Tests\Unit\Rules;

use App\Rules\CodedRule;
use App\Rules\HostName;
use App\Rules\HttpUrl;
use App\Rules\KeycloakRealm;
use App\Rules\KeycloakVersion;
use App\Rules\LdapAttributeName;
use App\Rules\LdapFilter;
use App\Rules\RedirectUri;
use App\Rules\ScopeList;
use App\Rules\UniqueAttributeValues;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T013 (research R10, FR-010 to FR-013, FR-044): each rule of feature 012 reports the contract's
 * rule code and parameters.
 */
class IdentityProviderRulesTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $expected  code and params, or null when valid
     */
    private function assertRule(CodedRule $rule, mixed $value, ?array $expected, string $attribute = 'field'): void
    {
        $failed = false;
        $rule->validate($attribute, $value, function (string $message, ?string $key = null) use (&$failed): PotentiallyTranslatedString {
            $failed = true;

            return new PotentiallyTranslatedString($message, new Translator(new ArrayLoader, 'en'));
        });

        if ($expected === null) {
            $this->assertFalse($failed, 'expected '.json_encode($value).' to be valid');

            return;
        }

        $this->assertTrue($failed, 'expected '.json_encode($value).' to be refused');
        $violation = $rule->violationFor($attribute);
        $this->assertNotNull($violation);
        $this->assertSame($expected, ['code' => $violation['code'], 'params' => $violation['params']]);
        $this->assertNotSame('', $violation['message']);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function httpUrls(): iterable
    {
        yield 'https' => ['https://sso.example.org', null];
        yield 'http with path and port' => ['http://keycloak:8080/auth', null];
        yield 'ftp' => ['ftp://x.example.org', ['code' => 'invalidFormat', 'params' => ['format' => 'httpUrl']]];
        yield 'relative' => ['/relative', ['code' => 'invalidFormat', 'params' => ['format' => 'httpUrl']]];
        yield 'no host' => ['https://', ['code' => 'invalidFormat', 'params' => ['format' => 'httpUrl']]];
        yield 'whitespace' => ['https://a b.example', ['code' => 'invalidFormat', 'params' => ['format' => 'httpUrl']]];
        yield '2,001 characters' => ['https://example.org/'.str_repeat('a', 1981), ['code' => 'tooLong', 'params' => ['max' => 2000]]];
        yield 'not a string' => [42, ['code' => 'invalidType', 'params' => ['expected' => 'string']]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('httpUrls')]
    public function test_http_url(mixed $value, ?array $expected): void
    {
        $this->assertRule(new HttpUrl, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function realms(): iterable
    {
        yield 'plain' => ['example', null];
        yield 'with slash' => ['a/b', ['code' => 'invalidFormat', 'params' => ['format' => 'keycloakRealm']]];
        yield '256 characters' => [str_repeat('r', 256), ['code' => 'tooLong', 'params' => ['max' => 255]]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('realms')]
    public function test_keycloak_realm(mixed $value, ?array $expected): void
    {
        $this->assertRule(new KeycloakRealm, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function versions(): iterable
    {
        yield 'three parts' => ['26.0.7', null];
        yield 'one part' => ['26', null];
        yield 'four parts' => ['26.0.7.1', null];
        yield 'five parts' => ['26.0.7.1.2', ['code' => 'invalidFormat', 'params' => ['format' => 'keycloakVersion']]];
        yield 'prefix' => ['v26', ['code' => 'invalidFormat', 'params' => ['format' => 'keycloakVersion']]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('versions')]
    public function test_keycloak_version(mixed $value, ?array $expected): void
    {
        $this->assertRule(new KeycloakVersion, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function scopes(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'scopeList']];

        yield 'default' => ['openid profile email mailcow_template', null];
        yield 'one' => ['openid', null];
        yield '50 names' => [implode(' ', array_fill(0, 50, 's')), null];
        yield 'double space' => ['openid  profile', $invalid];
        yield 'quote' => ['openid "profile"', $invalid];
        yield 'backslash' => ['openid pro\\file', $invalid];
        yield '51 names' => [implode(' ', array_fill(0, 51, 's')), $invalid];
        yield 'empty' => ['', $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('scopes')]
    public function test_scope_list(mixed $value, ?array $expected): void
    {
        $this->assertRule(new ScopeList, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function hosts(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'hostName']];

        yield 'dns name' => ['ldap1.example.org', null];
        yield 'single label' => ['openldap', null];
        yield 'ipv4' => ['192.0.2.10', null];
        yield 'ipv6' => ['2001:db8::1', null];
        yield 'space' => ['bad host', $invalid];
        yield 'leading hyphen' => ['-ldap.example.org', $invalid];
        yield 'with port' => ['ldap.example.org:389', $invalid];
        yield 'too long' => [str_repeat('a.', 127).'ab', $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('hosts')]
    public function test_host_name(mixed $value, ?array $expected): void
    {
        $this->assertRule(new HostName, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function attributeNames(): iterable
    {
        yield 'mail' => ['mail', null];
        yield 'with hyphen and digits' => ['msDS-Attr2', null];
        yield 'leading digit' => ['1mail', ['code' => 'invalidFormat', 'params' => ['format' => 'ldapAttribute']]];
        yield 'underscore' => ['user_name', ['code' => 'invalidFormat', 'params' => ['format' => 'ldapAttribute']]];
        yield '256 characters' => ['a'.str_repeat('b', 255), ['code' => 'tooLong', 'params' => ['max' => 255]]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('attributeNames')]
    public function test_ldap_attribute_name(mixed $value, ?array $expected): void
    {
        $this->assertRule(new LdapAttributeName, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function filters(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'ldapFilter']];

        yield 'simple' => ['(objectClass=inetOrgPerson)', null];
        yield 'nested' => ['(&(objectClass=person)(|(ou=a)(ou=b)))', null];
        yield 'escaped parenthesis' => ['(a=\\29)', null];
        yield 'unbalanced' => ['(a=b', $invalid];
        yield 'no parentheses' => ['a=b', $invalid];
        yield 'closed early' => ['(a=b))(', $invalid];
        yield 'unescaped inner parenthesis' => ['(a=b)c)', $invalid];
        yield '1,001 characters' => ['(a='.str_repeat('b', 997).')', ['code' => 'tooLong', 'params' => ['max' => 1000]]];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('filters')]
    public function test_ldap_filter(mixed $value, ?array $expected): void
    {
        $this->assertRule(new LdapFilter, $value, $expected);
    }

    /**
     * @return iterable<string, array{mixed, array<string, mixed>|null}>
     */
    public static function redirectUris(): iterable
    {
        $invalid = ['code' => 'invalidFormat', 'params' => ['format' => 'redirectUri']];

        yield 'https' => ['https://wiki.example.org/oauth/callback', null];
        yield 'with query' => ['https://chat.example.org/cb?x=1', null];
        yield 'private-use scheme' => ['com.example.app:/cb', null];
        yield 'loopback' => ['http://127.0.0.1:8080/cb', null];
        yield 'fragment' => ['https://x.example.org/cb#f', $invalid];
        yield 'whitespace' => ['x y', $invalid];
        yield 'relative' => ['relative/cb', $invalid];
        yield 'absolute path' => ['/cb', $invalid];
        yield 'empty' => ['', $invalid];
    }

    /**
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('redirectUris')]
    public function test_redirect_uri(mixed $value, ?array $expected): void
    {
        $this->assertRule(new RedirectUri, $value, $expected);
    }

    public function test_unique_attribute_values_name_the_first_occurrence(): void
    {
        $values = ['staff', 'Staff', 'staff', 'student', 'staff'];
        $rule = new UniqueAttributeValues($values, 'attributeMapping.*.attributeValue');

        $this->assertRule($rule, 'staff', null, 'attributeMapping.0.attributeValue');
        $this->assertRule($rule, 'Staff', null, 'attributeMapping.1.attributeValue');
        $this->assertRule($rule, 'staff', ['code' => 'duplicate', 'params' => ['duplicateOf' => 'attributeMapping.0.attributeValue']], 'attributeMapping.2.attributeValue');
        $this->assertRule($rule, 'student', null, 'attributeMapping.3.attributeValue');
        $this->assertRule($rule, 'staff', ['code' => 'duplicate', 'params' => ['duplicateOf' => 'attributeMapping.0.attributeValue']], 'attributeMapping.4.attributeValue');
    }
}
