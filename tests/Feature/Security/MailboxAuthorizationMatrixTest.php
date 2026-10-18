<?php

namespace Tests\Feature\Security;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\FeatureRegistry;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T086 (constitution VI, quality gate 5, FR-001–FR-005, SC-004): every operation of
 * feature 003 for every kind of caller, derived from plan.md's operation table and the request
 * pipeline (key scope, authentication, anti-forgery, pending actions, role, scope).
 */
class MailboxAuthorizationMatrixTest extends TestCase
{
    use ApiKeys;

    private const CALLERS = [
        'anonymous',
        'pendingSignIn',
        'restricted',
        'adminSession',
        'domainAdminSession',
        'mailboxSession',
        'adminKey',
        'adminReadOnlyKey',
        'domainAdminKey',
        'domainAdminReadOnlyKey',
    ];

    /** Operations domain administrators may not use (FR-005): template writes. */
    private const ADMIN_ONLY = ['createMailboxTemplate', 'updateMailboxTemplate', 'deleteMailboxTemplate', 'applyMailboxTemplateBulkAction'];

    private int $templateId = 0;

    /** @var array<string, string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->legacyMailbox('anna@example.org');
        $f->legacyMailbox('carl@foreign.example');
        $f->defaultMailboxTemplate();
        $this->templateId = $f->mailboxTemplate('Small', ['quota' => 1073741824]);
        $f->admin('admin', MailcowFactory::PASSWORD, 'SSHA256');
        $f->admin('restricted', MailcowFactory::PASSWORD, 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], ['protocol_access' => 1], scheme: 'SSHA256');
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
            'listMailboxes' => ['GET', '/api/v2/mailboxes', null, 200],
            'createMailbox' => ['POST', '/api/v2/mailboxes', ['localPart' => 'new', 'domain' => 'example.org', 'password' => '{PLAIN}x'], 201],
            'getMailboxCapabilities' => ['GET', '/api/v2/capabilities/mailboxes', null, 200],
            'listMailboxTags' => ['GET', '/api/v2/mailbox-tags', null, 200],
            'applyMailboxBulkAction' => ['POST', '/api/v2/mailboxes/bulk-actions', ['action' => 'disallowProtocol', 'protocol' => 'pop3', 'targets' => [['id' => 'anna@example.org']]], 200],
            'getMailbox' => ['GET', '/api/v2/mailboxes/anna@example.org', null, 200],
            'updateMailbox' => ['PATCH', '/api/v2/mailboxes/anna@example.org', ['displayName' => 'Anna'], 200],
            'deleteMailbox' => ['DELETE', '/api/v2/mailboxes/anna@example.org', null, 204],
            'previewMailboxDeletion' => ['GET', '/api/v2/mailboxes/anna@example.org/deletion-preview', null, 200],
            'renameMailbox' => ['POST', '/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'anna.new', 'keepOldAddressAsAlias' => false], 200],
            'listMailboxTemplates' => ['GET', '/api/v2/mailbox-templates', null, 200],
            'createMailboxTemplate' => ['POST', '/api/v2/mailbox-templates', ['name' => 'Matrix'], 201],
            'getMailboxTemplate' => ['GET', '/api/v2/mailbox-templates/{template}', null, 200],
            'updateMailboxTemplate' => ['PATCH', '/api/v2/mailbox-templates/{template}', ['quota' => 2147483648], 200],
            'deleteMailboxTemplate' => ['DELETE', '/api/v2/mailbox-templates/{template}', null, 204],
            'applyMailboxTemplateBulkAction' => ['POST', '/api/v2/mailbox-templates/bulk-actions', ['action' => 'delete', 'targets' => [['id' => '{template}']]], 200],
            'getPasswordPolicy' => ['GET', '/api/v2/password-policy', null, 200],
        ];
    }

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
     * @return array{int, string|null}
     */
    private static function expected(string $operation, string $caller): array
    {
        [$method, , , $success] = self::requests()[$operation];

        if (in_array($caller, ['adminReadOnlyKey', 'domainAdminReadOnlyKey'], true) && $method !== 'GET') {
            return [403, 'insufficient-key-scope'];
        }

        return match ($caller) {
            'anonymous' => [401, 'unauthenticated'],
            'pendingSignIn' => [401, 'second-factor-required'],
            'restricted' => [403, 'pending-action-required'],
            'mailboxSession' => [403, 'forbidden'],
            'adminSession', 'adminKey', 'adminReadOnlyKey' => [$success, null],
            default => in_array($operation, self::ADMIN_ONLY, true) ? [403, 'forbidden'] : [$success, null],
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
            'mailboxSession' => $this->signInAs('user@users.example', Role::MailboxUser),
            default => $headers['X-API-Key'] = $this->keys[$caller],
        };

        if (! isset($headers['X-API-Key']) && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $this->csrfToken();
        }

        if ($body === null) {
            return $this->json($method, $path, [], $headers);
        }

        return $method === 'PATCH' ? $this->patchMerge($path, $body, $headers) : $this->json($method, $path, $body, $headers);
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
        $this->assertEqualsCanonicalizing(FeatureRegistry::load()->features['003-mailboxes']['operations'], array_keys(self::requests()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function domainAdministrators(): array
    {
        return ['session' => ['domainAdminSession'], 'read-write key' => ['domainAdminKey']];
    }

    #[DataProvider('domainAdministrators')]
    public function test_a_foreign_mailbox_is_not_found_for_domain_administrators(string $caller): void
    {
        foreach (['getMailbox', 'updateMailbox', 'deleteMailbox', 'previewMailboxDeletion', 'renameMailbox'] as $operation) {
            [$method, $path, $body] = self::requests()[$operation];
            $this->forgetCookies();

            $this->send($caller, $method, str_replace('anna@example.org', 'carl@foreign.example', $path), $body)->assertNotFound()->assertJsonPath('type', 'about:blank');
        }

        $this->assertNotNull(DB::table('mailbox')->where('username', 'carl@foreign.example')->first());
    }
}
