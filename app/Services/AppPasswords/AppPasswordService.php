<?php

namespace App\Services\AppPasswords;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Casts\MailcowDateTime;
use App\Services\Audit\AuditLogger;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\Passwords\PasswordPolicyReader;
use App\Services\SelfService\PermissionGate;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Passwords\NewPasswordValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The app passwords of a mailbox (feature 005 FR-037–FR-041, research R11), stored exactly as
 * legacy app_passwd() stores them, with the spec's fixes:
 *
 *  - names are trimmed and stored HTML-encoded (legacy's htmlspecialchars()), the encoded form at
 *    most 255 characters, and returned as plain text;
 *  - a password is given (no hash-scheme prefix, the policy met) or generated (32 characters at
 *    least) and returned once; it is hashed in `MAILCOW_PASS_SCHEME`;
 *  - protocols and the active state default to the legacy UI's (all six, active);
 *  - a change never rewrites the owning mailbox and always applies a new password (legacy assigns
 *    the app password to the editor and drops the password of administrators);
 *  - changes need `appPasswds` (enforced here; legacy only hides the tab), reads need no flag;
 *  - reads never select the hash, and the version tag carries only an HMAC of it.
 *
 * The caller resolves the mailbox in its scope (MailboxAccess). Every change shares a transaction
 * with its audit record; refusals are audited by the route.
 */
class AppPasswordService
{
    /** Contract protocol => `app_passwd` column, in the contract's order. */
    public const PROTOCOLS = ['imap' => 'imap_access', 'pop3' => 'pop3_access', 'smtp' => 'smtp_access', 'sieve' => 'sieve_access', 'activeSync' => 'eas_access', 'dav' => 'dav_access'];

    /** Contract sort value => column. */
    public const SORT_COLUMNS = ['name' => 'name', 'createdAt' => 'created', 'modifiedAt' => 'modified', 'active' => 'active'];

    public const MAX_NAME_LENGTH = 255;

    private const COLUMNS = ['id', 'name', 'mailbox', 'imap_access', 'pop3_access', 'smtp_access', 'sieve_access', 'eas_access', 'dav_access', 'active', 'created', 'modified'];

    public function __construct(
        private readonly PermissionGate $gate,
        private readonly NewPasswordValidator $rules,
        private readonly MailboxPasswordHasher $hasher,
        private readonly AppPasswordGenerator $generator,
        private readonly PasswordPolicyReader $policies,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(string $mailbox, ListQuery $query, ?bool $active): array
    {
        $builder = DB::table('app_passwd')->where('mailbox', $mailbox)->select(self::COLUMNS)->selectRaw($this->hmacColumn());

        if ($active !== null) {
            $active ? $builder->where('active', '!=', 0) : $builder->where('active', 0);
        }

        if ($query->search !== null) {
            $builder->whereRaw('LOWER(`name`) LIKE ?', [ListQuery::likePattern(mb_strtolower(self::encode($query->search)))]);
        }

        return $query->paginate($builder, fn (object $row): array => $this->present($row), 'id');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $mailbox, int $id): ?array
    {
        $row = $this->row($mailbox, $id);

        return $row === null ? null : $this->present($row);
    }

    /**
     * Creates an app password; returns it with the generated password, if any.
     *
     * @param  array<string, mixed>  $input  validated `AppPasswordCreateRequest` members
     * @return array<string, mixed> contract `AppPasswordResult`
     */
    public function create(Principal $principal, string $mailbox, array $input, ?string $source = null): array
    {
        $this->gate->requireChange($principal, PermissionFlag::AppPasswds);

        $violations = new FieldViolations;
        $name = $this->name((string) $input['name'], $violations);
        $given = isset($input['password']) && is_string($input['password']) ? $input['password'] : null;

        if ($given !== null) {
            $this->rules->validate($given, $violations, 'password');
        }

        $violations->throwIfAny();

        $password = $given ?? $this->generator->generate($this->policies->effective());
        $protocols = array_key_exists('protocols', $input) && is_array($input['protocols']) ? array_values(array_unique($input['protocols'])) : array_keys(self::PROTOCOLS);
        $active = ! array_key_exists('active', $input) || $input['active'] !== false;
        $domain = (string) DB::table('mailbox')->where('username', $mailbox)->value('domain');

        $id = DB::transaction(function () use ($principal, $mailbox, $domain, $name, $password, $protocols, $active, $given, $source): int {
            $id = (int) DB::table('app_passwd')->insertGetId(array_merge([
                'name' => $name,
                'mailbox' => $mailbox,
                'domain' => $domain,
                'password' => $this->hasher->hash($password)->value,
            ], self::protocolColumns($protocols), ['active' => (int) $active]));

            $this->audit->record(
                AuditEventType::AppPasswordCreation,
                true,
                $principal,
                ['type' => 'appPassword', 'id' => $mailbox, 'name' => self::decode($name)],
                null,
                null,
                array_filter([
                    'appPasswordId' => $id,
                    'values' => ['name' => self::decode($name), 'protocols' => self::orderedProtocols($protocols), 'active' => $active],
                    'passwordGenerated' => $given === null,
                    'source' => $source,
                ], fn (mixed $v): bool => $v !== null),
            );

            return $id;
        });

        return ($this->find($mailbox, $id) ?? throw new NotFoundHttpException) + ['generatedPassword' => $given === null ? $password : null];
    }

    /**
     * Changes the values sent of an app password.
     *
     * @param  array<string, mixed>  $patch  validated `AppPasswordPatch` members
     * @return array<string, mixed> contract `AppPasswordResult`
     */
    public function update(Request $request, Principal $principal, string $mailbox, int $id, array $patch): array
    {
        $generated = null;

        DB::transaction(function () use ($request, $principal, $mailbox, $id, $patch, &$generated): void {
            $row = $this->row($mailbox, $id, true) ?? throw new NotFoundHttpException('The app password does not exist.');

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The app password has changed.');
            }

            $violations = new FieldViolations;
            $set = [];
            $changes = [];
            $current = $this->present($row);

            if (array_key_exists('name', $patch)) {
                $name = $this->name((string) $patch['name'], $violations);

                if ($name !== (string) $row->name) {
                    $set['name'] = $name;
                    $changes['name'] = ['old' => $current['name'], 'new' => self::decode($name)];
                }
            }

            if (array_key_exists('protocols', $patch) && is_array($patch['protocols'])) {
                $protocols = self::orderedProtocols($patch['protocols']);

                if ($protocols !== $current['protocols']) {
                    $set += self::protocolColumns($protocols);
                    $changes['protocols'] = ['old' => $current['protocols'], 'new' => $protocols];
                }
            }

            if (array_key_exists('active', $patch) && (bool) $patch['active'] !== $current['active']) {
                $set['active'] = (int) $patch['active'];
                $changes['active'] = ['old' => $current['active'], 'new' => (bool) $patch['active']];
            }

            $password = null;

            if (isset($patch['password']) && is_string($patch['password'])) {
                $this->rules->validate($patch['password'], $violations, 'password');
                $password = $patch['password'];
                $changes['password'] = 'changed';
            } elseif (($patch['generatePassword'] ?? false) === true) {
                $password = $generated = $this->generator->generate($this->policies->effective());
                $changes['password'] = 'generated';
            }

            $violations->throwIfAny();
            $this->gate->requireChange($principal, PermissionFlag::AppPasswds, $changes !== []);

            if ($password !== null) {
                $set['password'] = $this->hasher->hash($password)->value;
            }

            if ($set !== []) {
                DB::table('app_passwd')->where('id', $id)->where('mailbox', $mailbox)->update($set);
            }

            $this->audit->record(
                AuditEventType::AppPasswordUpdate,
                true,
                $principal,
                ['type' => 'appPassword', 'id' => $mailbox, 'name' => isset($set['name']) ? self::decode($set['name']) : $current['name']],
                null,
                null,
                ['appPasswordId' => $id, 'changes' => $changes],
            );
        });

        return ($this->find($mailbox, $id) ?? throw new NotFoundHttpException) + ['generatedPassword' => $generated];
    }

    public function delete(Request $request, Principal $principal, string $mailbox, int $id): void
    {
        DB::transaction(function () use ($request, $principal, $mailbox, $id): void {
            $row = $this->row($mailbox, $id, true) ?? throw new NotFoundHttpException('The app password does not exist.');

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The app password has changed.');
            }

            $this->gate->requireChange($principal, PermissionFlag::AppPasswds);
            $current = $this->present($row);
            DB::table('app_passwd')->where('id', $id)->where('mailbox', $mailbox)->delete();

            $this->audit->record(
                AuditEventType::AppPasswordDeletion,
                true,
                $principal,
                ['type' => 'appPassword', 'id' => $mailbox, 'name' => $current['name']],
                null,
                null,
                ['appPasswordId' => $id, 'removed' => ['name' => $current['name'], 'protocols' => $current['protocols'], 'active' => $current['active']]],
            );
        });
    }

    /**
     * Refuses a change before anything is looked up, when the caller lacks `appPasswds`.
     */
    public function assertMayChange(Principal $principal): void
    {
        $this->gate->requireChange($principal, PermissionFlag::AppPasswds);
    }

    /**
     * Legacy's stored form of a name: trimmed and HTML-encoded (PHP's htmlspecialchars() defaults).
     */
    public static function encode(string $name): string
    {
        return htmlspecialchars(trim($name), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
    }

    public static function decode(string $stored): string
    {
        return htmlspecialchars_decode($stored, ENT_QUOTES | ENT_HTML401);
    }

    /**
     * @param  list<mixed>  $protocols
     * @return array<string, int>
     */
    private static function protocolColumns(array $protocols): array
    {
        $columns = [];

        foreach (self::PROTOCOLS as $protocol => $column) {
            $columns[$column] = (int) in_array($protocol, $protocols, true);
        }

        return $columns;
    }

    /**
     * @param  list<mixed>  $protocols
     * @return list<string>
     */
    private static function orderedProtocols(array $protocols): array
    {
        return array_values(array_filter(array_keys(self::PROTOCOLS), fn (string $p): bool => in_array($p, $protocols, true)));
    }

    private function name(string $name, FieldViolations $violations): string
    {
        $stored = self::encode($name);

        if (trim($name) === '') {
            $violations->add('name', 'required');
        } elseif (mb_strlen($stored) > self::MAX_NAME_LENGTH) {
            $violations->add('name', 'tooLong', ['max' => self::MAX_NAME_LENGTH, 'stored' => true], 'The name, with its special characters encoded as stored, must be at most 255 characters long.');
        }

        return $stored;
    }

    private function row(string $mailbox, int $id, bool $lock = false): ?object
    {
        $query = DB::table('app_passwd')->where('mailbox', $mailbox)->where('id', $id)->select(self::COLUMNS)->selectRaw($this->hmacColumn());

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * The HMAC of the stored hash (keyed with the app key), computed by MariaDB so that the hash
     * itself never leaves the database.
     */
    private function hmacColumn(): string
    {
        return 'SHA2(CONCAT('.DB::getPdo()->quote((string) config('app.key')).', `password`), 256) AS `password_tag`';
    }

    /**
     * @return array<string, mixed> contract `AppPassword`
     */
    private function present(object $row): array
    {
        $protocols = [];

        foreach (self::PROTOCOLS as $protocol => $column) {
            if ((int) $row->{$column} !== 0) {
                $protocols[] = $protocol;
            }
        }

        return [
            'id' => (int) $row->id,
            'mailbox' => (string) $row->mailbox,
            'name' => self::decode((string) $row->name),
            'protocols' => $protocols,
            'active' => (int) $row->active !== 0,
            'createdAt' => (string) MailcowDateTime::toUtcString($row->created),
            'modifiedAt' => MailcowDateTime::toUtcString($row->modified),
            'etag' => $this->etag($row),
        ];
    }

    private function etag(object $row): string
    {
        $flags = array_map(fn (string $column): int => (int) $row->{$column}, array_values(self::PROTOCOLS));

        return ETag::of(['appPassword', (int) $row->id, (string) $row->name, (string) $row->mailbox, ...$flags, (int) $row->active, (string) $row->password_tag]);
    }
}
