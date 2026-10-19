<?php

namespace App\Services\Rspamd;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Support\Files\SharedFileMissing;
use App\Support\Files\SharedFileWriter;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The global Rspamd regex maps (feature 013 FR-056 to FR-059, research R13): the maps the
 * operator's `$RSPAMD_MAPS['regex']` makes editable, read from and written to the files of the
 * mounted `data/conf/rspamd/custom`, where Rspamd reads them. A map is written only when its file
 * exists (`409 regex-map-unavailable` otherwise; legacy silently skips it), in place and in one
 * write, as `trim(content)` plus a line break (legacy's format), and touched again after the clock
 * passed a second, so that Rspamd reloads the complete map without a restart. The version tag
 * covers the file content; the legacy UI never sends one and can still overwrite a change.
 *
 * The audit record (old and new size) is written in a database transaction around the file
 * write; when it fails, the old content is written back.
 */
class RspamdRegexMapService
{
    public function __construct(
        private readonly MailcowConfig $config,
        private readonly SharedFileWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Summaries of the configured maps (gap G17: lists carry no content), in the configured order
     * unless sorted by `name` or `id`; `q` searches the name.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $query, bool $sorted): array
    {
        $maps = [];

        foreach ($this->config->rspamdRegexMaps() as $name => $file) {
            if ($query->search !== null && ! str_contains(mb_strtolower((string) $name), mb_strtolower($query->search))) {
                continue;
            }

            $content = $this->writer->read($this->path($file));
            $maps[] = ['id' => $file, 'name' => (string) $name, 'available' => $content !== null, 'sizeBytes' => strlen((string) $content), 'etag' => self::etag($file, $content)];
        }

        if ($sorted) {
            $key = $query->sort === 'id' ? 'id' : 'name';
            usort($maps, fn (array $a, array $b): int => strcasecmp((string) $a[$key], (string) $b[$key]) ?: strcmp((string) $a['id'], (string) $b['id']));

            if ($query->order === 'desc') {
                $maps = array_reverse($maps);
            }
        }

        return ['data' => array_slice($maps, $query->offset, $query->limit), 'meta' => $query->meta(count($maps))];
    }

    /**
     * Contract `RspamdRegexMap`, or null when the configuration does not make the map editable.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $file): ?array
    {
        $name = $this->name($file);

        return $name === null ? null : $this->present($file, $name, $this->writer->read($this->path($file)));
    }

    /**
     * Replaces a map's content (lines already validated); null with `validateOnly` after the
     * existence checks.
     *
     * @return array<string, mixed>|null
     */
    public function replace(string $file, string $content, ?string $ifMatch, Principal $actor, bool $validateOnly = false): ?array
    {
        $name = $this->name($file) ?? throw new NotFoundHttpException('The regex map does not exist.');
        $path = $this->path($file);

        if (! is_file($path)) {
            throw new ProblemException(ProblemType::RegexMapUnavailable);
        }

        if ($validateOnly) {
            return null;
        }

        $bytes = trim($content)."\n";

        DB::beginTransaction();
        $old = null;

        try {
            $old = $this->writer->replaceInPlace($path, $bytes, $ifMatch, fn (string $current): string => self::etag($file, $current), touchAfter: true);
            $this->audit->record(AuditEventType::RspamdRegexMapReplacement, true, $actor, ['type' => 'rspamdRegexMap', 'id' => $file, 'name' => $name], null, null, ['oldBytes' => strlen($old), 'newBytes' => strlen($bytes)]);
            DB::commit();
        } catch (SharedFileMissing) {
            DB::rollBack();

            throw new ProblemException(ProblemType::RegexMapUnavailable);
        } catch (Throwable $e) {
            DB::rollBack();

            if ($old !== null) {
                $this->restore($path, $old);
            }

            throw $e;
        }

        return $this->present($file, $name, $bytes);
    }

    private function restore(string $path, string $old): void
    {
        try {
            $this->writer->replaceInPlace($path, $old, null, fn (string $c): string => '');
        } catch (Throwable $e) {
            Log::error('A regex map could not be restored after its audit record failed: '.$e->getMessage());
        }
    }

    private function name(string $file): ?string
    {
        $name = array_search($file, $this->config->rspamdRegexMaps(), true);

        return is_string($name) ? $name : null;
    }

    private function path(string $file): string
    {
        $dir = (string) config('restv2.rspamd_map_dir');
        $dir = str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));

        return $dir.'/'.$file;
    }

    private static function etag(string $file, ?string $content): string
    {
        return ETag::of(['rspamdRegexMap', $file, $content === null ? null : hash('sha256', $content)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $file, string $name, ?string $content): array
    {
        return ['id' => $file, 'name' => $name, 'available' => $content !== null, 'content' => (string) $content, 'etag' => self::etag($file, $content)];
    }
}
