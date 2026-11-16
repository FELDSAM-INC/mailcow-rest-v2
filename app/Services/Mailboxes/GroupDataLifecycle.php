<?php

namespace App\Services\Mailboxes;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;

/** Maintains 2026-11 group references when existing v2 account/address operations run. */
final class GroupDataLifecycle
{
    public function mailboxDeleted(string $address): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return;
        }
        $groups = DB::table('mailbox_group_member')->where('username', $address)->pluck('group_id')->all();
        DB::table('mailbox_group_member')->where('username', $address)->delete();
        $this->addressDeleted($address);
        foreach ($groups as $id) {
            $group = DB::table('mailbox_group')->where('id', $id)->lockForUpdate()->first();
            if ($group === null) {
                continue;
            }
            $members = DB::table('mailbox_group_member')->where('group_id', $id)->orderBy('username')->pluck('username')->all();
            DB::table('alias')->where('group_id', $id)->update([
                'goto' => $members === [] ? 'null@localhost' : implode(',', $members),
                'active' => $members === [] ? 0 : (int) $group->active,
            ]);
        }
        // The mailbox deletion removes its SOGo ACLs. Remove their ownership ledger as well.
        DB::table('mailbox_group_sogo_acl')->where('c_uid', $address)->delete();
    }

    public function addressDeleted(string $address): void
    {
        if (SchemaVersion::hasNovember2026Features()) {
            DB::table('mailbox_group_send_as')->where('address', $address)->delete();
            DB::table('sender_acl')->whereNotNull('group_id')->where('send_as', $address)->delete();
        }
    }

    public function addressRenamed(string $old, string $new): void
    {
        if (SchemaVersion::hasNovember2026Features()) {
            // Merge duplicate grants without deleting either group's grant on the new address.
            $groups = DB::table('mailbox_group_send_as')->where('address', $old)->pluck('group_id');
            foreach ($groups as $id) {
                DB::table('mailbox_group_send_as')->insertOrIgnore(['group_id' => $id, 'address' => $new]);
            }
            DB::table('mailbox_group_send_as')->where('address', $old)->where('address', '<>', $new)->delete();
            DB::table('sender_acl')->whereNotNull('group_id')->where('send_as', $old)->update(['send_as' => $new]);
        }
    }

    public function domainDeleted(string $domain): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return;
        }
        DB::table('mailbox_group_send_as')->whereRaw("SUBSTRING_INDEX(`address`, '@', -1) = ?", [$domain])->delete();
        DB::table('sender_acl')->whereNotNull('group_id')->whereRaw("SUBSTRING_INDEX(`send_as`, '@', -1) = ?", [$domain])->delete();
        DB::table('imapsync_source_domain')->where('domain', $domain)->delete();
        $this->cleanupSogoAcl();
    }

    /** Called after FK cascades remove a group or an owner. Never removes manually shared ACLs. */
    public function cleanupSogoAcl(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return;
        }
        $ids = DB::table('mailbox_group_sogo_acl as l')->leftJoin('mailbox_group_sogo_grant as g', 'g.id', '=', 'l.grant_id')
            ->whereNull('g.id')->pluck('l.acl_id')->all();
        if ($ids !== []) {
            DB::table('sogo_acl')->whereIn('id', $ids)->delete();
            DB::table('mailbox_group_sogo_acl')->whereIn('acl_id', $ids)->delete();
        }
    }
}
