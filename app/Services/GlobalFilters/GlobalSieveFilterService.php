<?php

namespace App\Services\GlobalFilters;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Support\Files\SharedFileMissing;
use App\Support\Files\SharedFileWriter;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use App\Support\Runtime\TimeLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The global Sieve pre- and post-filter (feature 013 FR-062 to FR-065, research R14): the shared
 * files `before` and `after` of the mounted `data/conf/dovecot/global_sieve_{before,after}`, which
 * Dovecot copies and compiles when it starts and the legacy UI edits. A script (line endings
 * normalised and validated by the Form Request) is written in place only when its file exists
 * (`409 global-sieve-filter-unavailable` otherwise, without a restart; legacy restarts anyway), then
 * Dovecot is restarted through mailcow's container service as legacy does, unless deferred. A failed
 * or unanswered restart never undoes the write; it is reported in `meta.dovecotRestart` and the
 * audit record.
 *
 * Order (audit records are immutable and carry the restart outcome): database transaction, write,
 * restart, audit record, commit. When the record fails, the old script is written back and the
 * request fails; a restart that already happened then runs the new script until Dovecot's next
 * start, which is logged.
 */
class GlobalSieveFilterService
{
    /** Contract identifier => file. */
    public const FILES = ['prefilter' => 'before', 'postfilter' => 'after'];

    public function __construct(
        private readonly SharedFileWriter $writer,
        private readonly DockerApiClient $docker,
        private readonly AuditLogger $audit,
        private readonly MailcowConfig $config,
    ) {}

    /**
     * @return list<array{id: string, script: string, etag: string}>
     */
    public function all(): array
    {
        return array_map(fn (string $id): array => $this->find($id), array_keys(self::FILES));
    }

    /**
     * Contract `GlobalSieveFilter`; a missing file reads as an empty script.
     *
     * @return array{id: string, script: string, etag: string}
     */
    public function find(string $id): array
    {
        $content = $this->writer->read($this->path($id));

        return ['id' => $id, 'script' => (string) $content, 'etag' => self::etag($id, $content)];
    }

    /**
     * Replaces a script (normalised and validated) and restarts Dovecot unless deferred.
     *
     * @return array{filter: array{id: string, script: string, etag: string}, restart: array{outcome: string, detail: string|null}}|null null with `validateOnly`
     */
    public function replace(string $id, string $script, bool $deferRestart, ?string $ifMatch, Principal $actor, bool $validateOnly = false): ?array
    {
        $path = $this->path($id);

        if (! is_file($path)) {
            throw new ProblemException(ProblemType::GlobalSieveFilterUnavailable);
        }

        if ($validateOnly) {
            return null;
        }

        DB::beginTransaction();
        $old = null;
        $restart = ['outcome' => 'deferred', 'detail' => null];

        try {
            $old = $this->writer->replaceInPlace($path, $script, $ifMatch, fn (string $current): string => self::etag($id, $current));

            if (! $deferRestart) {
                $restart = $this->restart();
            }

            $this->audit->record(AuditEventType::GlobalSieveFilterReplacement, true, $actor, ['type' => 'globalSieveFilter', 'id' => $id, 'name' => null], null, null, [
                'oldBytes' => strlen($old),
                'newBytes' => strlen($script),
                'dovecotRestart' => $restart['outcome'],
                'restartDetail' => $restart['detail'],
            ]);
            DB::commit();
        } catch (SharedFileMissing) {
            DB::rollBack();

            throw new ProblemException(ProblemType::GlobalSieveFilterUnavailable);
        } catch (Throwable $e) {
            DB::rollBack();

            if ($old !== null) {
                $this->restore($path, $old, $restart['outcome'] === 'done');
            }

            throw $e;
        }

        return ['filter' => $this->find($id), 'restart' => $restart];
    }

    /**
     * @return array{outcome: string, detail: string|null}
     */
    private function restart(): array
    {
        TimeLimit::raise($this->config->dockerTimeout() + 20);
        $result = $this->docker->restart((string) config('restv2.monitoring.dovecot_container', 'dovecot-mailcow'));

        return match (true) {
            $result->isSuccess() => ['outcome' => 'done', 'detail' => null],
            $result->isUnknown() => ['outcome' => 'failed', 'detail' => 'mailcow\'s container service did not answer within DOCKER_TIMEOUT; the restart may still happen.'],
            default => ['outcome' => 'failed', 'detail' => (string) ($result->message ?? 'mailcow\'s container service reported a failure.')],
        };
    }

    private function restore(string $path, string $old, bool $restarted): void
    {
        try {
            $this->writer->replaceInPlace($path, $old, null, fn (string $c): string => '');
        } catch (Throwable $e) {
            Log::error('A global Sieve filter could not be restored after its audit record failed: '.$e->getMessage());

            return;
        }

        if ($restarted) {
            Log::error('A global Sieve filter was restored after its audit record failed, but Dovecot was already restarted with the new script; it runs that script until its next start.');
        }
    }

    private function path(string $id): string
    {
        $dir = (string) config('restv2.global_sieve_dir');
        $dir = str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));

        return $dir.'/'.self::FILES[$id];
    }

    private static function etag(string $id, ?string $content): string
    {
        return ETag::of(['globalSieveFilter', $id, $content === null ? null : hash('sha256', $content)]);
    }
}
