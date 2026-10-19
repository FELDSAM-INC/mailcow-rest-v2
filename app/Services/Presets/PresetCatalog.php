<?php

namespace App\Services\Presets;

use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The presets mailcow ships (feature 013 FR-061, research R22): the YAML files of the mounted
 * `data/web/inc/presets/{rspamd,sieve}` (001's inc mount), read on every request, so that local
 * changes of the deployment apply. The identifier is the file name; a headline `lang.<key>` gives
 * the translation key (`admin.<key>` for rspamd, `mailbox.<key>` for sieve, legacy's bases) and the
 * English text of mailcow's `lang.en-gb.json`; a literal headline has no key. Sorted naturally by
 * identifier (`sieve_2` before `sieve_10`); `q` searches the headline and the content.
 */
class PresetCatalog
{
    public const KINDS = ['rspamd' => 'admin', 'sieve' => 'mailbox'];

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(string $kind, ListQuery $query): array
    {
        $presets = $this->all($kind);

        if ($query->search !== null) {
            $needle = mb_strtolower($query->search);
            $presets = array_values(array_filter($presets, fn (array $p): bool => str_contains(mb_strtolower((string) $p['headline']), $needle) || str_contains(mb_strtolower((string) $p['content']), $needle)));
        }

        usort($presets, fn (array $a, array $b): int => strnatcmp((string) $a['id'], (string) $b['id']));

        if ($query->order === 'desc') {
            $presets = array_reverse($presets);
        }

        return ['data' => array_slice($presets, $query->offset, $query->limit), 'meta' => $query->meta(count($presets))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(string $kind): array
    {
        $base = self::KINDS[$kind];
        $english = $this->english();
        $presets = [];

        foreach (glob(self::directory().'/presets/'.$kind.'/*.yml') ?: [] as $path) {
            try {
                $preset = Yaml::parseFile($path);
            } catch (ParseException $e) {
                Log::warning("The preset {$path} is not valid YAML and is left out: ".$e->getMessage());

                continue;
            }

            if (! is_array($preset)) {
                continue;
            }

            $headline = is_scalar($preset['headline'] ?? null) ? (string) $preset['headline'] : '';
            $key = str_starts_with($headline, 'lang.') ? trim(substr($headline, 5)) : null;
            $text = $key !== null && is_string($english[$base][$key] ?? null) ? $english[$base][$key] : ($key ?? $headline);

            $presets[] = [
                'id' => basename($path, '.yml'),
                'kind' => $kind,
                'translationKey' => $key === null ? null : "{$base}.{$key}",
                'headline' => $text,
                'content' => is_scalar($preset['content'] ?? null) ? (string) $preset['content'] : '',
            ];
        }

        return $presets;
    }

    /**
     * @return array<string, mixed>
     */
    private function english(): array
    {
        $dir = (string) config('restv2.mailcow_lang_dir');
        $dir = str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
        $decoded = json_decode((string) @file_get_contents($dir.'/lang.en-gb.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function directory(): string
    {
        $dir = trim((string) config('restv2.mailcow_inc_dir'));

        return str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
    }
}
