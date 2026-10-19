<?php

namespace App\Services\Accounts;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The writes of administrator and domain administrator accounts (feature 007 FR-017 to FR-033,
 * research R6 to R10, R13), shared by AdminAccountService and DomainAdminAccountService, which add
 * what is particular to their role: the last-administrator protection, or the domains and
 * permission flags. Every write runs in one transaction with its audit record, checks the version
 * tag (`If-Match`) inside it, and throws the documented problem without writing anything on
 * failure. Validated values come from the Form Requests; bulk actions use the same methods.
 */
abstract class AccountService
{
    public function __construct(
        protected readonly AccountRepresentation $accounts,
        protected readonly AccountRenamer $renamer,
        protected readonly AccountDeleter $deleter,
        protected readonly SecondFactorSuspender $suspender,
        protected readonly MailboxPasswordHasher $hasher,
        protected readonly AuditLogger $audit,
    ) {}

    abstract protected function role(): Role;

    /**
     * Runs a write in its transaction (administrators: under the last-administrator guard).
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    abstract protected function transaction(callable $write): mixed;

    /**
     * Creates the account; returns its username.
     *
     * @param  array{username: string, password: string, active?: bool, forcePasswordChange?: bool, forceSecondFactorSetup?: bool, domains?: list<string>, permissions?: array<string, bool>}  $values
     *
     * @throws ProblemException account-exists
     */
    public function create(Principal $actor, array $values): string
    {
        $username = $values['username'];
        $hashed = $this->hasher->hash($values['password']);
        $active = $values['active'] ?? true;
        $forcePw = $values['forcePasswordChange'] ?? false;
        $forceTfa = $values['forceSecondFactorSetup'] ?? false;

        try {
            $this->transaction(function () use ($actor, $values, $username, $hashed, $active, $forcePw, $forceTfa): void {
                if (DB::table('admin')->where('username', $username)->lockForUpdate()->exists()) {
                    throw new ProblemException(ProblemType::AccountExists, "An administrator or domain administrator named {$username} already exists.");
                }

                // Rows that no account owns under this name (left by legacy) are removed, so that
                // the new account inherits nothing (FR-036).
                foreach (['domain_admins', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
                    DB::table($table)->where('username', $username)->delete();
                }

                DB::table('restv2_preferences')->where('account_kind', 'admin')->where('username', $username)->delete();

                DB::table('admin')->insert([
                    'username' => $username,
                    'password' => $hashed->value,
                    'superadmin' => $this->role() === Role::Admin ? 1 : 0,
                    'active' => $active ? 1 : 0,
                    'attributes' => json_encode(['force_tfa' => $forceTfa ? '1' : '0', 'force_pw_update' => $forcePw ? '1' : '0']),
                ]);

                $details = $this->createRoleRows($username, $active, $values) + [
                    'active' => $active,
                    'forcePasswordChange' => $forcePw,
                    'forceSecondFactorSetup' => $forceTfa,
                    'passwordPreHashed' => $hashed->preHashed,
                ];

                $this->audit->record($this->creationEvent(), true, $actor, $this->target($username), null, null, $details);
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e)
                ? new ProblemException(ProblemType::AccountExists, "An administrator or domain administrator named {$username} already exists.")
                : $e;
        }

        return $username;
    }

    /**
     * Applies a validated merge patch; returns the account's (new) username. A patch that changes
     * nothing writes nothing and is not audited.
     *
     * @param  array<string, mixed>  $values  `username`, `password`, `active`, the forced flags, and the role's own members
     *
     * @throws ProblemException account-exists, last-active-admin
     */
    public function update(Principal $actor, string $username, array $values, ?string $ifMatch, ?Session $callerSession = null, ?string $bulkRequestId = null): string
    {
        try {
            return $this->transaction(function () use ($actor, $username, $values, $ifMatch, $callerSession, $bulkRequestId): string {
                $row = $this->lockedRow($username, $ifMatch);
                $old = (string) $row->username;
                $changes = [];
                $set = [];
                $attributes = [];

                if (array_key_exists('active', $values) && $values['active'] !== ((int) $row->active === 1)) {
                    $changes['active'] = ['old' => (int) $row->active === 1, 'new' => (bool) $values['active']];
                    $set['active'] = $values['active'] ? 1 : 0;
                }

                foreach (['forcePasswordChange' => 'force_pw_update', 'forceSecondFactorSetup' => 'force_tfa'] as $member => $attribute) {
                    $current = AccountVersion::attribute($row->attributes ?? null, $attribute);

                    if (array_key_exists($member, $values) && $values[$member] !== $current) {
                        $changes[$member] = ['old' => $current, 'new' => (bool) $values[$member]];
                        $attributes[$attribute] = $values[$member] ? '1' : '0';
                    }
                }

                $details = [];

                if (array_key_exists('password', $values)) {
                    $hashed = $this->hasher->hash((string) $values['password']);
                    $set['password'] = $hashed->value;
                    $details['passwordChanged'] = true;
                    $details['passwordPreHashed'] = $hashed->preHashed;
                }

                if ($attributes !== []) {
                    // Legacy's statement: only the forced flags change; other attributes are kept.
                    $pairs = array_map(
                        fn (string $attribute, string $value): string => DB::getPdo()->quote('$.'.$attribute).', '.DB::getPdo()->quote($value),
                        array_keys($attributes),
                        $attributes,
                    );
                    $set['attributes'] = DB::raw("JSON_SET(COALESCE(`attributes`, '{}'), ".implode(', ', $pairs).')');
                }

                if ($set !== []) {
                    DB::table('admin')->where('username', $old)->update($set);
                }

                $details += $this->updateRoleRows($row, $values, $set);

                $new = array_key_exists('username', $values) ? (string) $values['username'] : mb_strtolower($old);

                if ($new !== mb_strtolower($old)) {
                    $changes['username'] = ['old' => $old, 'new' => $new];
                    $this->renamer->rename($old, $new, $actor, $callerSession);
                } else {
                    $new = $old;
                }

                if ($changes === [] && $details === []) {
                    return $new;
                }

                if ($changes !== []) {
                    ksort($changes);
                    $details = ['changes' => $changes] + $details;
                }

                if ($bulkRequestId !== null) {
                    $details['bulkRequestId'] = $bulkRequestId;
                }

                $this->audit->record($this->updateEvent(), true, $actor, $this->target($old), null, null, $details);

                return $new;
            });
        } catch (QueryException $e) {
            throw self::isDuplicate($e)
                ? new ProblemException(ProblemType::AccountExists, 'An administrator or domain administrator with the new username already exists.')
                : $e;
        }
    }

    /**
     * Deletes the account with the full cascade (AccountDeleter).
     *
     * @throws ProblemException cannot-delete-own-account, last-active-admin
     */
    public function delete(Principal $actor, string $username, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $this->transaction(function () use ($actor, $username, $ifMatch, $bulkRequestId): void {
            $row = $this->lockedRow($username, $ifMatch);
            $this->beforeDeletion($actor, $row);
            $this->deleter->delete($this->role(), (string) $row->username, $actor, $bulkRequestId);
        });
    }

    /**
     * Suspends the account's second factors until its next password sign-in.
     */
    public function suspendSecondFactors(Principal $actor, string $username, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($actor, $username, $ifMatch, $bulkRequestId): void {
            $row = $this->lockedRow($username, $ifMatch);
            $details = ['suspendedCount' => $this->suspender->suspend((string) $row->username)];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record($this->suspensionEvent(), true, $actor, $this->target((string) $row->username), null, null, $details);
        }, 3);
    }

    /**
     * The stored username of an account of the role, or null (bulk targets).
     */
    public function resolve(string $username): ?string
    {
        $row = $this->accounts->row($this->role(), $username);

        return $row === null ? null : (string) $row->username;
    }

    /**
     * The account's current version tag (bulk results).
     */
    public function version(string $username): ?string
    {
        $row = $this->accounts->row($this->role(), $username);

        return $row === null ? null : $this->accounts->version($this->role(), $row);
    }

    /**
     * The role's rows of a new account; returns the role's creation details.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function createRoleRows(string $username, bool $active, array $values): array
    {
        return [];
    }

    /**
     * The role's changes of an account, before a rename; returns the role's update details.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $set  the `admin` columns the change wrote
     * @return array<string, mixed>
     */
    protected function updateRoleRows(stdClass $row, array $values, array $set): array
    {
        return [];
    }

    /**
     * Checks a deletion before it runs (administrators: not the own account).
     */
    protected function beforeDeletion(Principal $actor, stdClass $row): void {}

    /**
     * The audit target of an account: `{type: admin | domainAdmin, id: username, name: username}`.
     *
     * @return array{type: string, id: string, name: string}
     */
    public function target(string $username): array
    {
        return ['type' => $this->role() === Role::Admin ? 'admin' : 'domainAdmin', 'id' => $username, 'name' => $username];
    }

    /**
     * The locked `admin` row of the account, after the version check.
     *
     * @throws NotFoundHttpException|PreconditionFailedHttpException
     */
    protected function lockedRow(string $username, ?string $ifMatch): stdClass
    {
        $row = $this->accounts->row($this->role(), $username, lock: true)
            ?? throw new NotFoundHttpException('The account does not exist.');

        if (ETag::preconditionFails($ifMatch, $this->accounts->version($this->role(), $row, lock: true))) {
            throw new PreconditionFailedHttpException('The account was changed since it was read.');
        }

        return $row;
    }

    private function creationEvent(): AuditEventType
    {
        return $this->role() === Role::Admin ? AuditEventType::AdminCreation : AuditEventType::DomainAdminCreation;
    }

    private function updateEvent(): AuditEventType
    {
        return $this->role() === Role::Admin ? AuditEventType::AdminUpdate : AuditEventType::DomainAdminUpdate;
    }

    private function suspensionEvent(): AuditEventType
    {
        return $this->role() === Role::Admin ? AuditEventType::AdminSecondFactorSuspension : AuditEventType::DomainAdminSecondFactorSuspension;
    }

    private static function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
