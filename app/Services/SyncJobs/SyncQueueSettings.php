<?php

namespace App\Services\SyncJobs;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

final class SyncQueueSettings
{
    public const COLUMNS = ['maxParallel' => 'max_parallel', 'maxBytesPerSecond' => 'max_bytes_per_second'];

    public function authorize(Principal $actor): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AccessDeniedHttpException;
        }
        if (! SchemaVersion::hasNovember2026Features()) {
            throw new ConflictHttpException('Sync queue settings require mailcow 2026-11.');
        }
    }

    /** @return array{maxParallel: int, maxBytesPerSecond: int, etag: string} */
    public function read(Principal $actor): array
    {
        $this->authorize($actor);
        $values = DB::table('imapsync_settings')->pluck('value', 'name');
        $data = ['maxParallel' => (int) ($values['max_parallel'] ?? 1), 'maxBytesPerSecond' => (int) ($values['max_bytes_per_second'] ?? 0)];

        return $data + ['etag' => ETag::of($data)];
    }

    /** @param array<string, int> $patch
     * @return array{maxParallel: int, maxBytesPerSecond: int, etag: string}
     */
    public function save(Principal $actor, array $patch, ?string $ifMatch): array
    {
        $this->authorize($actor);
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            throw new AccessDeniedHttpException;
        }

        return app(NamedLock::class)->run(['restv2_sync_queue'], fn (): array => DB::transaction(function () use ($actor, $patch, $ifMatch): array {
            $current = $this->read($actor);
            if (ETag::preconditionFails($ifMatch, $current['etag'])) {
                throw new PreconditionFailedHttpException;
            }
            foreach ($patch as $field => $value) {
                DB::table('imapsync_settings')->updateOrInsert(['name' => self::COLUMNS[$field]], ['value' => (string) $value]);
            }
            app(AuditLogger::class)->record(AuditEventType::SyncQueueSettingsUpdate, true, $actor, ['type' => 'syncQueue', 'id' => 'settings'], null, null, ['fields' => array_keys($patch)]);

            return $this->read($actor);
        }));
    }
}
