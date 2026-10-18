<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\RoutingEmail;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * BCC map writes, legacy bcc('add' / 'edit' / 'delete') (feature 009 FR-049–FR-055, FR-084,
 * research R18). Creations and type changes run under the `bcc_maps` named lock, so that at most
 * one map exists per local destination and type also under concurrent requests. The local
 * destination and the domain never change after creation. Each write is audited in its
 * transaction. The caller's role, flag and scope are checked before (RoutingPolicy, BccMapQuery).
 */
class BccMapService implements RoutingBulkHandler
{
    public const WRITABLE = ['type', 'bccDestination', 'active'];

    public function __construct(
        private readonly BccMapQuery $query,
        private readonly BccDestinationResolver $destinations,
        private readonly RoutingAudit $audit,
        private readonly RoutingLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>  $members  validated BccMapCreateRequest members
     */
    public function create(Principal $principal, array $members): int
    {
        $violations = new FieldViolations;
        $local = $this->destinations->resolve($principal, (string) $members['localDestination']);

        if ($local === null) {
            $violations->add('localDestination', 'notFound', [], 'No domain, alias domain, mailbox or alias with this value exists in your scope.');
        }

        self::addViolation($violations, 'bccDestination', self::bccViolation($members['bccDestination'] ?? null));
        $violations->throwIfAny();

        $type = $members['type'] === 'recipient' ? 'rcpt' : 'sender';
        $bcc = (string) RoutingEmail::normalize((string) $members['bccDestination']);
        $active = (bool) ($members['active'] ?? true);
        /** @var array{localDestination: string, domain: string} $local */

        return $this->lock->run(RoutingLock::BCC_MAPS, function () use ($principal, $local, $type, $bcc, $active): int {
            $this->assertUnique($local['localDestination'], $type, null, 'localDestination');

            $id = (int) DB::table('bcc_maps')->insertGetId([
                'local_dest' => $local['localDestination'],
                'bcc_dest' => $bcc,
                'domain' => $local['domain'],
                'active' => (int) $active,
                'type' => $type,
            ]);

            $this->audit->success(AuditEventType::BccMapCreation, $principal, RoutingAudit::target('bccMap', $id, $local['localDestination']), ['values' => [
                'localDestination' => $local['localDestination'],
                'type' => $type === 'rcpt' ? 'recipient' : 'sender',
                'bccDestination' => $bcc,
                'domain' => $local['domain'],
                'active' => $active,
            ]]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $this->lock->run(RoutingLock::BCC_MAPS, function () use ($principal, $id, $patch, $ifMatch, $bulkRequestId): void {
            $row = DB::table('bcc_maps')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The BCC map does not exist.');

            if (ETag::preconditionFails($ifMatch, BccMapQuery::version($row))) {
                throw new PreconditionFailedHttpException('The BCC map was changed since it was read.');
            }

            $violations = new FieldViolations;
            $presented = BccMapQuery::present($this->query->find($principal, $id) ?? $row);
            $values = self::values($row, $presented, $patch, $violations);
            $violations->throwIfAny();

            if ($values['type'] !== (string) $row->type) {
                $this->assertUnique((string) $row->local_dest, $values['type'], $id, 'type');
            }

            $columns = [];
            $changes = [];

            foreach (['type' => 'type', 'bcc_dest' => 'bccDestination', 'active' => 'active'] as $column => $member) {
                $old = $column === 'active' ? (int) $row->active : (string) $row->{$column};

                if ($old === $values[$column]) {
                    continue;
                }

                $columns[$column] = $values[$column];
                $changes[$member] = match ($member) {
                    'type' => ['old' => $old === 'rcpt' ? 'recipient' : 'sender', 'new' => $values[$column] === 'rcpt' ? 'recipient' : 'sender'],
                    'active' => ['old' => $old !== 0, 'new' => $values[$column] !== 0],
                    default => ['old' => $old, 'new' => $values[$column]],
                };
            }

            if ($columns !== []) {
                DB::table('bcc_maps')->where('id', $id)->update($columns);
            }

            $this->audit->success(AuditEventType::BccMapUpdate, $principal, RoutingAudit::target('bccMap', $id, (string) $row->local_dest), ['changes' => (object) $changes], $bulkRequestId);
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): void {
            $row = DB::table('bcc_maps')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundHttpException('The BCC map does not exist.');

            if (ETag::preconditionFails($ifMatch, BccMapQuery::version($row))) {
                throw new PreconditionFailedHttpException('The BCC map was changed since it was read.');
            }

            DB::table('bcc_maps')->where('id', $id)->delete();

            $this->audit->success(AuditEventType::BccMapDeletion, $principal, RoutingAudit::target('bccMap', $id, (string) $row->local_dest), [
                'localDestination' => (string) $row->local_dest,
                'type' => (string) $row->type === 'rcpt' ? 'recipient' : 'sender',
            ], $bulkRequestId);
        });
    }

    /**
     * The stored values a patch results in (`type` as stored, `bcc_dest`, `active`), with
     * violations for read-only and unknown members and for the BCC destination.
     *
     * @param  array<string, mixed>  $presented
     * @param  array<string, mixed>  $patch
     * @return array{type: string, bcc_dest: string, active: int}
     */
    public static function values(object $row, array $presented, array $patch, FieldViolations $violations): array
    {
        PatchMembers::check($patch, self::WRITABLE, array_intersect_key($presented, array_flip(['id', 'localDestination', 'domain', 'orphaned', 'createdAt', 'modifiedAt', 'etag'])), $violations);

        $bcc = (string) $row->bcc_dest;

        if (array_key_exists('bccDestination', $patch)) {
            $violation = self::bccViolation($patch['bccDestination']);
            self::addViolation($violations, 'bccDestination', $violation);
            $bcc = $violation === null ? (string) RoutingEmail::normalize((string) $patch['bccDestination']) : $bcc;
        }

        $type = (string) $row->type;

        if (array_key_exists('type', $patch) && in_array($patch['type'], ['sender', 'recipient'], true)) {
            $type = $patch['type'] === 'recipient' ? 'rcpt' : 'sender';
        }

        return [
            'type' => $type,
            'bcc_dest' => $bcc,
            'active' => array_key_exists('active', $patch) && is_bool($patch['active']) ? (int) $patch['active'] : (int) $row->active,
        ];
    }

    /**
     * The violation of a BCC destination (FR-052): exactly one valid address of at most 255
     * characters; a list of addresses is `tooMany` (`max: 1`).
     *
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    public static function bccViolation(mixed $value): ?array
    {
        if (! is_string($value)) {
            return ApiFormRequest::make('invalidType', ['expected' => 'string']);
        }

        $value = trim($value);

        if ($value === '') {
            return ApiFormRequest::make('required', []);
        }

        if (mb_strlen($value) > RoutingEmail::MAX_LENGTH) {
            return ApiFormRequest::make('tooLong', ['max' => RoutingEmail::MAX_LENGTH]);
        }

        $pieces = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($pieces) > 1 && array_filter($pieces, fn (string $p): bool => RoutingEmail::normalize($p) === null) === []) {
            return ApiFormRequest::make('tooMany', ['max' => 1], 'Exactly one address is allowed; use an alias for several recipients.');
        }

        return RoutingEmail::normalize($value) === null ? ApiFormRequest::make('invalidFormat', ['format' => 'email']) : null;
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::BccMapDeletion : AuditEventType::BccMapUpdate;
    }

    public function bulkResolve(Principal $principal, string $id): ?string
    {
        $resolved = $this->query->resolve($principal, $id);

        return $resolved === null ? null : (string) $resolved;
    }

    public function bulkApply(Principal $principal, string $key, string $action, ?string $ifMatch, string $bulkRequestId): ?string
    {
        if ($action === 'delete') {
            $this->delete($principal, (int) $key, $ifMatch, $bulkRequestId);

            return null;
        }

        $this->update($principal, (int) $key, match ($action) {
            'activate' => ['active' => true],
            'deactivate' => ['active' => false],
            'switchToSender' => ['type' => 'sender'],
            default => ['type' => 'recipient'],
        }, $ifMatch, $bulkRequestId);
        $row = DB::table('bcc_maps')->where('id', (int) $key)->first();

        return $row === null ? null : BccMapQuery::version($row);
    }

    public function bulkAuditTarget(string $id, ?string $key): array
    {
        return RoutingAuditTargets::entryTarget('bccMap', $key ?? $id);
    }

    /**
     * `409 routing-entry-exists` when another map has the local destination and type.
     */
    private function assertUnique(string $localDestination, string $type, ?int $except, string $field): void
    {
        $query = DB::table('bcc_maps')->where('local_dest', $localDestination)->where('type', $type);

        if ($except !== null) {
            $query->where('id', '<>', $except);
        }

        $existing = $query->orderBy('id')->lockForUpdate()->value('id');

        if ($existing !== null) {
            throw RoutingConflict::entryExists($field, (int) $existing, 'A BCC map of this type already exists for this local destination.');
        }
    }

    /**
     * @param  array{code: string, params: array<string, mixed>, message: string}|null  $violation
     */
    private static function addViolation(FieldViolations $violations, string $field, ?array $violation): void
    {
        if ($violation !== null) {
            $violations->add($field, $violation['code'], $violation['params'], $violation['message']);
        }
    }
}
