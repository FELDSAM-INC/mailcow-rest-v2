<?php

namespace App\Services\MailboxGroups;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Mailboxes\AddressLock;
use App\Services\Mailboxes\GroupDataLifecycle;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

final class GroupService
{
    private const COLUMNS = ['domain' => 'domain', 'name' => 'name', 'description' => 'description', 'address' => 'address',
        'active' => 'active', 'senderAllowed' => 'sender_allowed', 'sogoVisible' => 'sogo_visible', 'internal' => 'internal',
        'templateId' => 'template_id', 'priority' => 'priority', 'identityReference' => 'idp_ref'];

    private const DEFAULTS = ['description' => '', 'address' => null, 'active' => 1, 'sender_allowed' => 0,
        'sogo_visible' => 0, 'internal' => 0, 'template_id' => null, 'priority' => 0, 'idp_ref' => null];

    public function __construct(private readonly GroupScope $scope, private readonly GroupReader $reader,
        private readonly GroupValidator $validator, private readonly GroupRelations $relations,
        private readonly GroupEffects $effects, private readonly GroupTemplates $templates,
        private readonly NamedLock $lock, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function save(?int $id, array $patch, Principal $actor, ?string $ifMatch): array
    {
        $this->scope->authorize($actor);
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            throw new AccessDeniedHttpException;
        }
        $locks = ['restv2_mailbox_groups'];
        if (is_string($patch['address'] ?? null)) {
            $locks[] = AddressLock::name($patch['address']);
        }

        return $this->lock->run($locks, function () use ($id, $patch, $actor, $ifMatch): array {
            try {
                [$groupId, $snapshot, $affected] = DB::transaction(function () use ($id, $patch, $actor, $ifMatch): array {
                    $old = $id === null ? null : $this->scope->query($actor)->where('id', $id)->lockForUpdate()->first();
                    if ($id !== null) {
                        if ($old === null) {
                            throw new NotFoundHttpException;
                        }
                        $this->assertEditable($id, $actor, $ifMatch);
                    }
                    $values = array_intersect_key((array) $old + self::DEFAULTS, array_flip(self::COLUMNS));
                    foreach (self::COLUMNS as $field => $column) {
                        if (array_key_exists($field, $patch)) {
                            $values[$column] = is_bool($patch[$field]) ? (int) $patch[$field] : $patch[$field];
                        }
                    }
                    $values['name'] = trim($values['name']);
                    $values['domain'] = strtolower($values['domain']);
                    $values['address'] = $values['address'] === null ? null : strtolower($values['address']);
                    $this->validator->validate($values, $old, $actor);
                    $this->validator->relations($patch, $actor, $values['address'], $id);
                    $before = $id === null ? [] : DB::table('mailbox_group_member')->where('group_id', $id)->pluck('username')->all();
                    $affected = array_values(array_unique(array_merge($before, array_map(strtolower(...), $patch['members'] ?? []))));
                    $snapshot = $this->templates->snapshot(array_values(array_filter($affected, fn ($m): bool => $this->scope->mailbox($actor, $m))));
                    $groupId = $id ?? (int) DB::table('mailbox_group')->insertGetId($values);
                    if ($id !== null) {
                        DB::table('mailbox_group')->where('id', $id)->update($values);
                    }
                    $this->relations->write($groupId, $patch, $actor);
                    $this->relations->rebuild($groupId);
                    $this->audit->record($id === null ? AuditEventType::MailboxGroupCreation : AuditEventType::MailboxGroupUpdate,
                        true, $actor, ['type' => 'mailboxGroup', 'id' => (string) $groupId], null, null,
                        ['fields' => array_keys($patch), 'domain' => $values['domain']]);

                    return [$groupId, $snapshot, $affected];
                });
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ConflictHttpException('A group name, distribution address or identity reference is already in use.', $e);
                }
                throw $e;
            }
            $warnings = array_merge($this->effects->apply($groupId, $affected),
                $this->templates->applyChanged($snapshot, $actor, $actor->role === Role::Admin && ($patch['reapplyTemplate'] ?? false)));
            $data = $this->reader->read($groupId, $actor);
            $data['warnings'] = array_merge($data['warnings'], $warnings);

            return $data;
        });
    }

    /** @return list<string> */
    public function delete(int $id, Principal $actor, ?string $ifMatch): array
    {
        $this->scope->authorize($actor);

        return $this->lock->run(['restv2_mailbox_groups'], function () use ($id, $actor, $ifMatch): array {
            [$snapshot, $owners, $members] = DB::transaction(function () use ($id, $actor, $ifMatch): array {
                if (! $this->scope->query($actor)->where('id', $id)->lockForUpdate()->exists()) {
                    throw new NotFoundHttpException;
                }
                $this->assertEditable($id, $actor, $ifMatch);
                $members = DB::table('mailbox_group_member')->where('group_id', $id)->pluck('username')->all();
                $snapshot = $this->templates->snapshot(array_values(array_filter($members, fn ($m): bool => $this->scope->mailbox($actor, $m))));
                $owners = DB::table('mailbox_group_imap_grant')->where('group_id', $id)->pluck('owner')->all();
                DB::table('mailbox_group')->where('id', $id)->delete();
                app(GroupDataLifecycle::class)->cleanupSogoAcl();
                $this->audit->record(AuditEventType::MailboxGroupDeletion, true, $actor, ['type' => 'mailboxGroup', 'id' => (string) $id], null, null, []);

                return [$snapshot, $owners, $members];
            });
            $warnings = $this->effects->removeDeleted($id, $owners);

            return array_merge($warnings, $this->effects->apply($id, $members), $this->templates->applyChanged($snapshot, $actor));
        });
    }

    private function assertEditable(int $id, Principal $actor, ?string $ifMatch): void
    {
        if (! $this->scope->editable($actor, $id)) {
            throw new AccessDeniedHttpException('The group contains grants outside your scope.');
        }
        if (ETag::preconditionFails($ifMatch, $this->reader->read($id, $actor)['etag'])) {
            throw new PreconditionFailedHttpException('The group changed since it was read.');
        }
    }
}
