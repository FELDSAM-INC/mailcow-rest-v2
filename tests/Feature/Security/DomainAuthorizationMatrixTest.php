<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Database\Factories\MailcowRows;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\FeatureRegistry;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 002 T089 (constitution VI, quality gate 5, FR-001, FR-003–FR-005, SC-004): every
 * operation of feature 002 for every kind of caller, derived from plan.md's operation table and the
 * request pipeline (key scope, authentication, anti-forgery, pending actions, role, scope).
 */
class DomainAuthorizationMatrixTest extends TestCase
{
    use ApiKeys;

    private const CALLERS = [
        'anonymous',
        'pendingSignIn',
        'restricted',
        'adminSession',
        'domainAdminSession',
        'domainAdminSessionWithoutDesc',
        'mailboxSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminKey',
        'domainAdminReadOnlyKey',
    ];

    private int $templateId = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('alias.example', 'example.org');
        $f->defaultDomainTemplate(['key_size' => 1024]);
        $this->templateId = $f->domainTemplate('Small', MailcowRows::DEFAULT_DOMAIN_TEMPLATE);
        $f->admin('admin', MailcowFactory::PASSWORD, 'SSHA256');
        $f->admin('restricted', MailcowFactory::PASSWORD, 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['domain_desc' => 1], scheme: 'SSHA256');
        $f->domainAdmin('limited', ['example.org'], ['domain_desc' => 0], scheme: 'SSHA256');
        $f->domain('users.example');
        $f->mailbox('user@users.example', scheme: 'SSHA256');
        $this->keys = [
            'adminKey' => $this->apiKey('admin', 'readWrite')['secret'],
            'adminReadOnlyKey' => $this->apiKey('admin', 'readOnly')['secret'],
            'domainAdminKey' => $this->apiKey('reseller', 'readWrite')['secret'],
            'domainAdminReadOnlyKey' => $this->apiKey('reseller', 'readOnly')['secret'],
        ];
    }

    /**
     * operation => [method, path, body or null, success status]. `{template}` is a template ID.
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    private static function requests(): array
    {
        return [
            'listDomains' => ['GET', '/api/v2/domains', null, 200],
            'createDomain' => ['POST', '/api/v2/domains', ['name' => 'new.example', 'dkimSelector' => ''], 201],
            'applyDomainBulkAction' => ['POST', '/api/v2/domains/bulk-actions', ['action' => 'deactivate', 'targets' => [['id' => 'example.org']]], 200],
            'getDomain' => ['GET', '/api/v2/domains/example.org', null, 200],
            'updateDomain' => ['PATCH', '/api/v2/domains/example.org', ['globalAddressList' => false], 200],
            'updateDomainDescription' => ['PATCH', '/api/v2/domains/example.org', ['description' => 'New text'], 200],
            'deleteDomain' => ['DELETE', '/api/v2/domains/example.org', null, 204],
            'previewDomainDeletion' => ['GET', '/api/v2/domains/example.org/deletion-preview', null, 200],
            'getDomainFooter' => ['GET', '/api/v2/domains/example.org/footer', null, 200],
            'replaceDomainFooter' => ['PUT', '/api/v2/domains/example.org/footer', ['html' => 'x', 'plain' => 'x', 'skipReplies' => false], 200],
            'getDomainsCapabilities' => ['GET', '/api/v2/capabilities/domains', null, 200],
            'listDomainTags' => ['GET', '/api/v2/domain-tags', null, 200],
            'listDomainTemplates' => ['GET', '/api/v2/domain-templates', null, 200],
            'createDomainTemplate' => ['POST', '/api/v2/domain-templates', ['name' => 'Matrix'], 201],
            'getDomainTemplate' => ['GET', '/api/v2/domain-templates/{template}', null, 200],
            'updateDomainTemplate' => ['PATCH', '/api/v2/domain-templates/{template}', ['maxMailboxes' => 3], 200],
            'deleteDomainTemplate' => ['DELETE', '/api/v2/domain-templates/{template}', null, 204],
            'applyDomainTemplateBulkAction' => ['POST', '/api/v2/domain-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '{template}']]], 200],
        ];
    }

    /** Operations domain administrators may use (FR-005). */
    private const DOMAIN_ADMIN = ['listDomains', 'getDomain', 'updateDomain', 'updateDomainDescription', 'getDomainFooter', 'replaceDomainFooter', 'getDomainsCapabilities', 'listDomainTags'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (array_keys(self::requests()) as $operation) {
            foreach (self::CALLERS as $caller) {
                $cases["{$operation} as {$caller}"] = [$operation, $caller];
            }
        }

        return $cases;
    }

    /**
     * @return array{int, string|null} status and problem type name (null: none or about:blank)
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success] = self::requests()[$operation];
        $unsafe = $method !== 'GET';

        if (in_array($caller, ['adminReadOnlyKey', 'domainAdminReadOnlyKey'], true) && $unsafe) {
            return [403, 'insufficient-key-scope'];
        }

        return match ($caller) {
            'anonymous' => [401, 'unauthenticated'],
            'pendingSignIn' => [401, 'second-factor-required'],
            'restricted' => [403, 'pending-action-required'],
            'mailboxSession' => [403, 'forbidden'],
            'adminSession', 'adminKey', 'adminReadOnlyKey' => [$success, null],
            default => match (true) {
                ! in_array($operation, self::DOMAIN_ADMIN, true) => [403, 'forbidden'],
                $operation === 'updateDomainDescription' && $caller === 'domainAdminSessionWithoutDesc' => [403, 'attribute-not-permitted'],
                default => [$success, null],
            },
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function send(string $caller, string $method, string $path, ?array $body): TestResponse
    {
        $headers = [];

        match ($caller) {
            'anonymous' => null,
            'pendingSignIn' => $this->signInPending('admin', Role::Admin),
            'restricted' => $this->signInAs('restricted', Role::Admin),
            'adminSession' => $this->signInAs('admin', Role::Admin),
            'domainAdminSession' => $this->signInAs('reseller', Role::DomainAdmin),
            'domainAdminSessionWithoutDesc' => $this->signInAs('limited', Role::DomainAdmin),
            'mailboxSession' => $this->signInAs('user@users.example', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        if ($body === null) {
            return $this->json($method, $path, [], $headers);
        }

        return $method === 'PATCH'
            ? $this->patchMerge($path, $body, $headers)
            : $this->json($method, $path, $body, $headers);
    }

    #[DataProvider('cases')]
    public function test_every_operation_for_every_caller(string $operation, string $caller): void
    {
        [$method, $path, $body] = self::requests()[$operation];
        [$status, $type] = self::expected($operation, $caller);

        $body = $body === null ? null : (array) json_decode(str_replace('{template}', (string) $this->templateId, (string) json_encode($body)), true);
        $response = $this->send($caller, $method, str_replace('{template}', (string) $this->templateId, $path), $body);

        $response->assertStatus($status);

        if ($type !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$type);
        }
    }

    public function test_the_matrix_covers_every_operation_of_the_feature(): void
    {
        $operations = array_values(array_diff(array_keys(self::requests()), ['updateDomainDescription']));

        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['002-domains']['operations'], $operations);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function domainAdministrators(): array
    {
        return ['session' => ['domainAdminSession'], 'read-write key' => ['domainAdminKey']];
    }

    #[DataProvider('domainAdministrators')]
    public function test_a_foreign_domain_is_not_found_for_domain_administrators(string $caller): void
    {
        foreach (['getDomain', 'updateDomain', 'getDomainFooter', 'replaceDomainFooter'] as $operation) {
            [$method, $path, $body] = self::requests()[$operation];
            $this->forgetCookies();

            $this->send($caller, $method, str_replace('example.org', 'foreign.example', $path), $body)->assertNotFound()->assertJsonPath('type', 'about:blank');
        }
    }

    public function test_an_alias_domain_name_is_not_found(): void
    {
        foreach (['getDomain', 'updateDomain', 'deleteDomain', 'previewDomainDeletion', 'getDomainFooter', 'replaceDomainFooter'] as $operation) {
            [$method, $path, $body] = self::requests()[$operation];
            $this->forgetCookies();

            $this->send('adminSession', $method, str_replace('example.org', 'alias.example', $path), $body)->assertNotFound();
        }
    }

    public function test_an_unknown_template_is_not_found_for_administrators_and_forbidden_for_others(): void
    {
        $this->send('adminSession', 'GET', '/api/v2/domain-templates/999999', null)->assertNotFound();

        foreach (['domainAdminSession', 'mailboxSession', 'domainAdminKey'] as $caller) {
            $this->forgetCookies();
            $this->send($caller, 'GET', '/api/v2/domain-templates/999999', null)->assertStatus(403);
        }
    }
}
