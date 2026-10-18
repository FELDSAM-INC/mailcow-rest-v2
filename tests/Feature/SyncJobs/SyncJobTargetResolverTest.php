<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\SyncJobs\SyncJobTargetResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T033 (research R8, FR-003, FR-017, US2 AS-9): the target mailbox of a new job. An
 * unknown or out-of-scope address is "not found"; a resource, an alias or an address of an alias
 * domain in scope is a validation error (`notEligible`); a mailbox resolves to its stored username.
 */
class SyncJobTargetResolverTest extends TestCase
{
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
        $f->mailboxWithDomain('inactive@example.org', active: 0);
        $f->resource('room@example.org', 'Room');
        $f->alias('info@example.org', 'alice@example.org');
        $f->alias('sales@example.net', 'carol@example.net');
        $f->aliasDomain('alias.example', 'example.org');
        $f->aliasDomain('alias.example.net', 'example.net');
    }

    private static function principal(string $username, Role $role): Principal
    {
        return new Principal($username, $role, $role->accountKind(), AuthMethod::Password);
    }

    /**
     * @return array{mailbox: string|null, violation: array{code: string, params: array<string, mixed>, message: string}|null}
     */
    private function resolve(mixed $mailbox, Principal $principal): array
    {
        return $this->app->make(SyncJobTargetResolver::class)->resolve($mailbox, $principal);
    }

    private function assertNotFound(mixed $mailbox, Principal $principal): void
    {
        try {
            $this->resolve($mailbox, $principal);
            $this->fail('The target '.json_encode($mailbox).' was not refused as not found.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertViolation(string $code, array $params, mixed $mailbox, Principal $principal): void
    {
        $result = $this->resolve($mailbox, $principal);

        $this->assertNull($result['mailbox']);
        $this->assertNotNull($result['violation']);
        $this->assertSame([$code, $params], [$result['violation']['code'], $result['violation']['params']]);
    }

    public function test_a_mailbox_user_s_job_always_targets_their_own_mailbox(): void
    {
        $user = self::principal('alice@example.org', Role::MailboxUser);

        $this->assertSame(['mailbox' => 'alice@example.org', 'violation' => null], $this->resolve(null, $user));
        $this->assertSame(['mailbox' => 'alice@example.org', 'violation' => null], $this->resolve('ALICE@Example.org', $user));
        $this->assertNotFound('carol@example.net', $user);
        $this->assertNotFound('nobody@example.org', $user);
    }

    public function test_an_administrator_names_an_existing_mailbox_and_gets_its_stored_username(): void
    {
        $admin = self::principal('admin', Role::Admin);

        $this->assertSame(['mailbox' => 'alice@example.org', 'violation' => null], $this->resolve('ALICE@example.org', $admin));
        $this->assertSame(['mailbox' => 'inactive@example.org', 'violation' => null], $this->resolve('inactive@example.org', $admin));
        $this->assertNotFound('nobody@example.org', $admin);
        $this->assertViolation('notEligible', ['kind' => 'resource'], 'room@example.org', $admin);
        $this->assertViolation('notEligible', ['kind' => 'alias'], 'info@example.org', $admin);
        $this->assertViolation('notEligible', ['kind' => 'aliasDomainAddress'], 'alice@alias.example', $admin);
        $this->assertViolation('required', [], null, $admin);
        $this->assertViolation('invalidFormat', ['format' => 'email'], 'example.org', $admin);
        $this->assertViolation('tooLong', ['max' => 255], str_repeat('a', 250).'@example.org', $admin);
        $this->assertViolation('invalidType', ['expected' => 'string'], 5, $admin);
    }

    public function test_a_domain_administrator_gets_not_found_for_anything_outside_their_domains(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $reseller = self::principal('reseller', Role::DomainAdmin);

        $this->assertSame(['mailbox' => 'alice@example.org', 'violation' => null], $this->resolve('alice@example.org', $reseller));
        $this->assertNotFound('carol@example.net', $reseller);
        $this->assertNotFound('sales@example.net', $reseller);
        $this->assertNotFound('carol@alias.example.net', $reseller);
        $this->assertViolation('notEligible', ['kind' => 'alias'], 'info@example.org', $reseller);
        $this->assertViolation('notEligible', ['kind' => 'aliasDomainAddress'], 'x@alias.example', $reseller);
    }
}
