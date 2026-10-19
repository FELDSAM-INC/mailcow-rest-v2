<?php

namespace App\Services\Accounts;

use App\Auth\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Passwords\MailboxPasswordHasher;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Domain administrators (feature 007 FR-018, FR-019, FR-023, FR-031, research R6, R7, R12): the
 * account writes of AccountService plus the assignments and the permission record.
 *
 *  - Creation stores one assignment per domain with the account's status (creation time NOW(), as
 *    legacy) and the permission record with only the sent flags, so that the others take the
 *    schema's defaults.
 *  - A sent domain set replaces the whole set: rows whose domain stays are kept untouched (their
 *    `id`, creation time and status), others are deleted (also names that are no primary domain,
 *    such as legacy's `ALL`), new ones get the account's status. A status change applies to the
 *    account and every assignment, as legacy does.
 *  - Sent permission flags change only those flags (DomainAdminPermissionService).
 */
class DomainAdminAccountService extends AccountService
{
    public function __construct(
        AccountRepresentation $accounts,
        AccountRenamer $renamer,
        AccountDeleter $deleter,
        SecondFactorSuspender $suspender,
        MailboxPasswordHasher $hasher,
        AuditLogger $audit,
        private readonly DomainAdminPermissionService $permissions,
    ) {
        parent::__construct($accounts, $renamer, $deleter, $suspender, $hasher, $audit);
    }

    protected function role(): Role
    {
        return Role::DomainAdmin;
    }

    protected function transaction(callable $write): mixed
    {
        return DB::transaction($write, 3);
    }

    protected function createRoleRows(string $username, bool $active, array $values): array
    {
        $domains = array_values(array_unique((array) ($values['domains'] ?? [])));
        sort($domains);

        foreach ($domains as $domain) {
            DB::table('domain_admins')->insert(['username' => $username, 'domain' => $domain, 'created' => DB::raw('NOW()'), 'active' => $active ? 1 : 0]);
        }

        $this->permissions->insertForNewAccount($username, (array) ($values['permissions'] ?? []));

        return [
            'domains' => $domains,
            'permissions' => DomainAdminFlags::fromRow($this->accounts->daAcl($username)),
        ];
    }

    protected function updateRoleRows(stdClass $row, array $values, array $set): array
    {
        $username = (string) $row->username;
        $active = array_key_exists('active', $set) ? (int) $set['active'] : (int) $row->active;
        $details = [];

        if (array_key_exists('active', $set)) {
            DB::table('domain_admins')->where('username', $username)->update(['active' => $active]);
        }

        if (array_key_exists('domains', $values)) {
            $details += $this->replaceDomains($username, (array) $values['domains'], $active);
        }

        if (array_key_exists('permissions', $values)) {
            $result = $this->permissions->apply($username, (array) $values['permissions'], $this->accounts->daAcl($username, lock: true));

            if ($result['changed']) {
                $details['permissionsChanged'] = $result['changes'];
            }
        }

        return $details;
    }

    /**
     * Replaces the assignment set; returns `domainsAdded` and `domainsRemoved` when it changed.
     *
     * @param  list<string>  $domains  normalised primary domains
     * @return array<string, list<string>>
     */
    private function replaceDomains(string $username, array $domains, int $active): array
    {
        $wanted = array_values(array_unique($domains));
        $kept = [];
        $removed = [];

        foreach ($this->accounts->assignments($username, lock: true) as $assignment) {
            $domain = (string) $assignment->domain;
            $key = mb_strtolower($domain);

            if (in_array($key, $wanted, true) && ! isset($kept[$key])) {
                $kept[$key] = true;

                continue;
            }

            DB::table('domain_admins')->where('id', $assignment->id)->delete();

            if (! in_array($key, $wanted, true)) {
                $removed[] = $domain;
            }
        }

        $added = array_values(array_filter($wanted, fn (string $domain): bool => ! isset($kept[$domain])));
        sort($added);

        foreach ($added as $domain) {
            DB::table('domain_admins')->insert(['username' => $username, 'domain' => $domain, 'created' => DB::raw('NOW()'), 'active' => $active]);
        }

        $removed = array_values(array_unique($removed));
        sort($removed);

        return $added === [] && $removed === [] ? [] : ['domainsAdded' => $added, 'domainsRemoved' => $removed];
    }
}
