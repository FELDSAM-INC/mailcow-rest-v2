<?php

namespace App\Services\MailboxGroups;

use App\Auth\Principal;
use App\Services\Mailboxes\GroupDataLifecycle;
use Illuminate\Support\Facades\DB;

/** Related and derived rows are changed atomically with the group. */
final class GroupRelations
{
    private const SOGO_ROLES = [
        'Calendar' => ['read' => ['PublicViewer', 'ConfidentialViewer', 'PrivateDAndTViewer'],
            'write' => ['PublicModifier', 'ConfidentialModifier', 'PrivateDAndTViewer', 'ObjectCreator', 'ObjectEraser']],
        'Contacts' => ['read' => ['ObjectViewer'], 'write' => ['ObjectViewer', 'ObjectCreator', 'ObjectEditor', 'ObjectEraser']],
    ];

    public function __construct(private readonly GroupScope $scope) {}

    /** @param array<string, mixed> $patch */
    public function write(int $id, array $patch, Principal $actor): void
    {
        if (array_key_exists('members', $patch)) {
            $wanted = array_fill_keys(array_map(strtolower(...), $patch['members']), true);
            foreach (DB::table('mailbox_group_member')->where('group_id', $id)->get() as $member) {
                if (! isset($wanted[$member->username]) && $member->source === 'manual' && $this->scope->mailbox($actor, $member->username)) {
                    DB::table('mailbox_group_member')->where('group_id', $id)->where('username', $member->username)->delete();
                }
            }
            foreach (array_keys($wanted) as $username) {
                DB::table('mailbox_group_member')->insertOrIgnore(['group_id' => $id, 'username' => $username, 'source' => 'manual']);
            }
        }
        if (array_key_exists('sendAs', $patch)) {
            DB::table('mailbox_group_send_as')->where('group_id', $id)->delete();
            foreach ($patch['sendAs'] as $address) {
                DB::table('mailbox_group_send_as')->insert(['group_id' => $id, 'address' => strtolower(trim($address))]);
            }
        }
        foreach (['imap' => 'imapGrants', 'sogo' => 'sogoGrants'] as $kind => $field) {
            if (! array_key_exists($field, $patch)) {
                continue;
            }
            $table = 'mailbox_group_'.$kind.'_grant';
            $keep = [];
            foreach ($patch[$field] as $grant) {
                $key = ['group_id' => $id, 'owner' => strtolower($grant['owner'])];
                if ($kind === 'sogo') {
                    $key += ['folder_type' => $grant['type'], 'folder' => $grant['folder']];
                }
                $values = $kind === 'imap' ? ['rights' => $grant['rights']] : ['access' => $grant['access']];
                $values['subscribe'] = (int) ($grant['subscribe'] ?? false);
                $current = DB::table($table)->where($key)->first();
                if ($current === null) {
                    $keep[] = DB::table($table)->insertGetId($key + $values);
                } else {
                    $keep[] = $current->id;
                    if (array_diff_assoc($values, (array) $current) !== [] || $current->state === 'removing') {
                        DB::table($table)->where('id', $current->id)->update($values + ['state' => 'pending', 'last_error' => null]);
                    }
                }
            }
            $removed = DB::table($table)->where('group_id', $id)->whereNotIn('id', $keep);
            // Keep an IMAP removal until Dovecot confirms it, including its owner and scope.
            $kind === 'imap' ? $removed->update(['state' => 'removing']) : $removed->delete();
        }
    }

    public function rebuild(int $id): void
    {
        $group = DB::table('mailbox_group')->where('id', $id)->firstOrFail();
        $members = DB::table('mailbox_group_member')->where('group_id', $id)->orderBy('username')->pluck('username')->all();
        if ($group->address === null) {
            DB::table('alias')->where('group_id', $id)->delete();
        } else {
            // The key is group_id, never address: no existing mailbox or alias is taken over.
            DB::table('alias')->updateOrInsert(['group_id' => $id], [
                'address' => $group->address, 'domain' => $group->domain,
                'goto' => $members === [] ? 'null@localhost' : implode(',', $members),
                'public_comment' => $group->description, 'sender_allowed' => $group->sender_allowed,
                'sogo_visible' => (int) ($group->sogo_visible && $group->sender_allowed),
                'internal' => $group->internal, 'active' => (int) ($group->active && $members !== []),
            ]);
        }
        DB::table('sender_acl')->where('group_id', $id)->delete();
        if ($group->active) {
            DB::table('sender_acl')->insertUsing(['logged_in_as', 'send_as', 'external', 'group_id'],
                DB::table('mailbox_group_member as m')->join('mailbox_group_send_as as s', 's.group_id', '=', 'm.group_id')
                    ->where('m.group_id', $id)->whereColumn('m.username', '<>', 's.address')
                    ->selectRaw('m.username, s.address, 0, m.group_id'));
        }
        $this->sogo($id, (bool) $group->active, $members);
    }

    /** @param list<string> $members */
    private function sogo(int $id, bool $active, array $members): void
    {
        app(GroupDataLifecycle::class)->cleanupSogoAcl();
        foreach (DB::table('mailbox_group_sogo_grant')->where('group_id', $id)->get() as $grant) {
            $object = '/'.$grant->owner.'/'.$grant->folder_type.'/'.$grant->folder;
            $folder = DB::table('sogo_folder_info')->where('c_path', '/Users'.$object)->value('c_folder_id');
            $wanted = [];
            if ($folder !== null && $active) {
                foreach ($members as $member) {
                    foreach ($member === $grant->owner ? [] : self::SOGO_ROLES[$grant->folder_type][$grant->access] as $role) {
                        $wanted[$member.'|'.$role] = [$member, $role];
                    }
                }
            }
            foreach (DB::table('mailbox_group_sogo_acl as l')->leftJoin('sogo_acl as a', 'a.id', '=', 'l.acl_id')
                ->where('l.grant_id', $grant->id)->select('l.*', 'a.c_folder_id', 'a.c_object')->get() as $row) {
                $key = $row->c_uid.'|'.$row->c_role;
                if (isset($wanted[$key]) && (string) $row->c_folder_id === (string) $folder && $row->c_object === $object) {
                    unset($wanted[$key]);
                } else {
                    DB::table('sogo_acl')->where('id', $row->acl_id)->delete();
                    DB::table('mailbox_group_sogo_acl')->where('acl_id', $row->acl_id)->delete();
                }
            }
            foreach ($wanted as [$member, $role]) {
                $acl = DB::table('sogo_acl')->insertGetId(['c_folder_id' => $folder, 'c_object' => $object, 'c_uid' => $member, 'c_role' => $role]);
                DB::table('mailbox_group_sogo_acl')->insert(['acl_id' => $acl, 'grant_id' => $grant->id, 'c_uid' => $member, 'c_role' => $role]);
            }
            DB::table('mailbox_group_sogo_grant')->where('id', $grant->id)->update([
                'state' => $folder === null ? 'pending' : 'applied', 'last_error' => $folder === null ? 'folder_missing' : null,
            ]);
        }
    }
}
