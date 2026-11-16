<?php

namespace App\Services\SyncJobs;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** No replacement account may inherit an old account's sources or OAuth tokens. */
final class SyncSourceAccountLifecycle
{
    public function deleted(string $username): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return;
        }
        $this->releaseSources($username);
        DB::table('imapsync_source_oauth_token')->where('owner', $username)->delete();
        DB::table('imapsync_source_user')->where('username', $username)->delete();
    }

    public function renamed(string $old, string $new): void
    {
        if (! SchemaVersion::hasNovember2026Features() || $old === $new) {
            return;
        }
        $this->deleted($new);
        DB::table('imapsync_source')->where('created_by', $old)->update(['created_by' => $new]);
        DB::table('imapsync_source_user')->where('username', $old)->update(['username' => $new]);
        DB::table('imapsync_source_oauth_token')->where('owner', $old)->update(['owner' => $new]);
    }

    private function releaseSources(string $username): void
    {
        foreach (DB::table('imapsync_source')->where('created_by', $username)->lockForUpdate()->get(['id', 'name']) as $source) {
            if (DB::table('imapsync')->where('source_id', $source->id)->exists()) {
                // Keep sources used by other accounts, under admin ownership and a unique name.
                DB::table('imapsync_source')->where('id', $source->id)->update([
                    'created_by' => '', 'name' => mb_substr((string) $source->name, 0, 60).' '.Str::uuid(),
                ]);

                continue;
            }
            // Mailbox rename disables FKs, so do not rely on cascades here.
            foreach (['imapsync_source_domain', 'imapsync_source_user', 'imapsync_source_oauth_token'] as $table) {
                DB::table($table)->where('source_id', $source->id)->delete();
            }
            DB::table('imapsync_source')->where('id', $source->id)->delete();
        }
    }
}
