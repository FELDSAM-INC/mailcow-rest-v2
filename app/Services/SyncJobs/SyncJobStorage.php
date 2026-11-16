<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Models\Mailcow\SchemaVersion;
use App\Models\Mailcow\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Keeps the v2 connection fields compatible with mailcow's source-based runner (2026-11). */
final class SyncJobStorage
{
    /**
     * Called inside the job transaction, after scope and retained-credential checks. A connection
     * edit creates a private source for this job; it must never redirect another job's credentials
     * by updating a shared source. Existing OAuth sources remain attached to unchanged jobs.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    public function columns(Principal $principal, string $mailbox, array $columns, ?SyncJob $job = null): array
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return $columns;
        }

        $connection = ['host1', 'port1', 'enc1', 'authmech1'];
        if (isset($columns['source_id'])) {
            $columns = array_diff_key($columns, array_flip($connection));
            if (array_key_exists('custom_params', $columns)) {
                $columns['custom_params'] = app(SyncJobParametersCodec::class)->encode((string) $columns['custom_params']);
            }

            return $columns;
        }
        if ($job === null || array_intersect(array_keys($columns), $connection) !== []) {
            if ($job !== null && (string) $job->getAttribute('authmech1') === 'XOAUTH2') {
                throw new ValidationProblemException(['remoteHost' => [ApiFormRequest::make('immutable', [],
                    'Change this OAuth connection through sync source management.')]]);
            }

            $stored = $job?->getAttributes() ?? [];
            $source = [];
            foreach ($connection as $column) {
                $source[$column === 'authmech1' ? 'auth_type' : $column] = $columns[$column] ?? $stored[$column] ?? null;
                unset($columns[$column]);
            }

            $source['auth_type'] ??= 'PLAIN';
            $source['name'] = mb_substr($source['host1'], 0, 60).' '.Str::uuid();
            $source['created_by'] = $principal->username;
            $source['scope'] = 'user';
            // New connections verify TLS; an endpoint edit also resets an old opt-out.
            $source['verify_cert'] = 1;
            $source['active'] = 1;
            $columns['source_id'] = DB::table('imapsync_source')->insertGetId($source);
            DB::table('imapsync_source_user')->insert(['source_id' => $columns['source_id'], 'username' => $mailbox]);
        }

        if (array_key_exists('custom_params', $columns)) {
            $columns['custom_params'] = app(SyncJobParametersCodec::class)->encode((string) $columns['custom_params']);
        }

        return $columns;
    }
}
