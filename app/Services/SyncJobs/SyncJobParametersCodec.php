<?php

namespace App\Services\SyncJobs;

use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\Config\MailcowConfig;

/** 2026-11 stores runner arguments as JSON pairs, never as a shell command. */
final class SyncJobParametersCodec
{
    /** Upstream 2026-11 vars.inc.php; used when the mounted configuration is unavailable. */
    private const DEFAULT_RULES = [
        'authmd51' => false,
        'authuser1' => true,
        'debug' => false,
        'debugcontent' => false,
        'debugcrossduplicates' => false,
        'debugflags' => false,
        'debugfolders' => false,
        'debugimap' => false,
        'debugimap1' => false,
        'debugmemory' => false,
        'debugssl' => true,
        'delete1emptyfolders' => false,
        'delete2folders' => false,
        'disarmreadreceipts' => false,
        'domain1' => true,
        'domino1' => false,
        'errorsmax' => true,
        'exchange1' => false,
        'exitwhenover' => true,
        'expunge1' => false,
        'f1f2' => true,
        'filterbuggyflags' => false,
        'folder' => true,
        'folderfirst' => true,
        'folderlast' => true,
        'folderrec' => true,
        'gmail1' => false,
        'idatefromheader' => false,
        'include' => true,
        'inet4' => false,
        'inet6' => false,
        'justconnect' => false,
        'justfolders' => false,
        'justfoldersizes' => false,
        'justlogin' => false,
        'keepalive1' => false,
        'log' => false,
        'maxbytesafter' => true,
        'maxlinelength' => true,
        'maxmessagespersecond' => true,
        'maxsize' => true,
        'maxsleep' => true,
        'minage' => true,
        'minsize' => true,
        'noabletosearch' => false,
        'noabletosearch1' => false,
        'noexpunge1' => false,
        'nofoldersizesatend' => false,
        'noid' => false,
        'nolog' => false,
        'nomixfolders' => false,
        'noresyncflags' => false,
        'nossl1' => false,
        'nosyncacls' => false,
        'notls1' => false,
        'nousecache' => false,
        'office1' => false,
        'prefix1' => true,
        'prefix2' => true,
        'proxyauth1' => false,
        'resyncflags' => false,
        'resynclabels' => false,
        'search' => true,
        'search1' => true,
        'sep1' => true,
        'sep2' => true,
        'skipemptyfolders' => false,
        'sslargs1' => '^SSL_verify_mode=[01]$',
        'subfolder1' => true,
        'subscribe' => false,
        'subscribed' => false,
        'syncacls' => false,
        'syncduplicates' => false,
        'syncinternaldates' => false,
        'synclabels' => false,
        'truncmess' => true,
        'usecache' => false,
        'useheader' => true,
        'useuid' => false,
    ];

    public function __construct(private readonly MailcowConfig $config) {}

    /** @return array<string, bool|string> */
    public function rules(): array
    {
        $rules = [];
        foreach ((array) $this->config->value('IMAPSYNC_OPTIONS') as $option => $rule) {
            $option = strtolower(ltrim(trim((string) $option), '-'));
            if (preg_match('/^[a-z][a-z0-9]*$/D', $option) === 1 && (is_bool($rule) || (is_string($rule) && $rule !== ''))) {
                $rules[$option] = $rule;
            }
        }

        return array_diff_key($rules === [] ? self::DEFAULT_RULES : $rules, array_flip(CustomParametersValidator::UNSAFE_OPTIONS));
    }

    public function encode(string $text): string
    {
        $violations = $this->violations($text);
        if ($violations !== []) {
            throw new ValidationProblemException(['customParameters' => $violations]);
        }

        return json_encode($this->pairs($text), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Keep ordinary parameters readable; JSON is used when a value contains an argument boundary
     * that cannot be represented losslessly by the older text field.
     */
    public static function display(string $stored): string
    {
        $pairs = json_decode($stored, true);
        if (! is_array($pairs) || ! array_is_list($pairs)) {
            return $stored;
        }
        $parts = [];
        foreach ($pairs as $pair) {
            if (! is_array($pair) || ! is_string($pair['o'] ?? null) || ! is_string($pair['v'] ?? null)
                || preg_match('/^[a-z][a-z0-9]*$/D', $pair['o']) !== 1
                || preg_match('/\s--|[\r\n\x00]/', $pair['v']) === 1 || trim($pair['v']) !== $pair['v']) {
                return $stored;
            }
            $parts[] = '--'.$pair['o'].($pair['v'] === '' ? '' : '='.$pair['v']);
        }

        return implode(' ', $parts);
    }

    /** @return list<array{code: string, params: array<string, mixed>, message: string}> */
    public function violations(string $text, bool $checkRules = true): array
    {
        $pairs = $this->pairs($text);
        if ($pairs === null || count($pairs) > 100) {
            return [ApiFormRequest::make('invalidFormat', ['format' => 'imapsyncParameters'], 'Use --option=value or a JSON list of option/value pairs.')];
        }
        $rules = $this->rules();
        $violations = [];
        foreach ($pairs as $pair) {
            $option = $pair['o'];
            $value = $pair['v'];
            $unsafe = in_array($option, CustomParametersValidator::UNSAFE_OPTIONS, true)
                || (! array_key_exists($option, $rules) && array_filter(CustomParametersValidator::UNSAFE_OPTIONS,
                    fn (string $blocked): bool => str_starts_with($blocked, $option)) !== []);
            if ($unsafe || ($checkRules && ! array_key_exists($option, $rules))) {
                $violations[] = ApiFormRequest::make('optionNotAllowed', ['option' => $option], "The imapsync option \"{$option}\" is not allowed.");

                continue;
            }
            $rule = $rules[$option] ?? true;
            $valid = ! $checkRules || match (true) {
                $rule === false => trim($value) === '',
                $rule === true => trim($value) !== '',
                default => @preg_match('~^(?:'.str_replace('~', '\\~', $rule).')$~D', $value) === 1,
            };
            if (! $valid || strlen($value) > 1000 || str_contains($value, "\0")) {
                $violations[] = ApiFormRequest::make('invalidFormat', ['format' => 'imapsyncOptionValue', 'option' => $option], "Invalid value for imapsync option \"{$option}\".");
            }
        }

        return $violations;
    }

    /** @return list<array{o: string, v: string}>|null */
    private function pairs(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $text === '0') {
            return [];
        }
        if (str_starts_with($text, '[')) {
            $pairs = json_decode($text, true);
            if (! is_array($pairs) || ! array_is_list($pairs)) {
                return null;
            }
        } else {
            $pairs = [];
            foreach (preg_split('/\s+(?=--)/', $text) ?: [] as $part) {
                if (preg_match('/^--([a-z][a-z0-9]*)(?:=(.*))?$/Dis', $part, $match) !== 1) {
                    return null;
                }
                $pairs[] = ['o' => strtolower($match[1]), 'v' => $match[2] ?? ''];
            }
        }
        foreach ($pairs as $pair) {
            if (! is_array($pair) || array_diff(array_keys($pair), ['o', 'v']) !== []
                || ! is_string($pair['o'] ?? null) || ! is_string($pair['v'] ?? null)
                || preg_match('/^[a-z][a-z0-9]*$/D', $pair['o']) !== 1) {
                return null;
            }
        }

        return $pairs;
    }
}
