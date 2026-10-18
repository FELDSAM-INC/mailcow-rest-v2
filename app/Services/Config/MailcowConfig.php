<?php

namespace App\Services\Config;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Values of mailcow's PHP configuration, vars.inc.php and vars.local.inc.php (research R14,
 * docs/conventions.md "Legacy configuration the API cannot read").
 *
 * Precedence per value: the API server setting RESTV2_<NAME> (`restv2.vars_overrides`), then the
 * operator's files in the mounted data/web/inc (`restv2.mailcow_inc_dir`), then the upstream
 * default of 2026-09a (`restv2.vars_defaults`). The files are evaluated by
 * resources/bin/export-mailcow-vars.php in a subprocess and the result is cached in Redis under
 * `restv2:mailcow-vars:<fingerprint>`, where the fingerprint covers path, size, modification time
 * and inode of both files: any change is picked up by the next call, with no time-based staleness.
 * When the files are missing or cannot be evaluated, the defaults apply and one warning is logged
 * per fingerprint.
 *
 * Later features add their variables to config/restv2.php (`vars_overrides`, `vars_defaults`) and
 * read them with value(); none writes its own reader.
 */
class MailcowConfig
{
    private const CACHE_PREFIX = 'mailcow-vars:';

    private const CACHE_TTL = 86400;

    private const TIMEOUT = 5.0;

    /** Variables that are a boolean or one of the strings required/preferred/discouraged. */
    private const FLAG_OR_STRING = ['FIDO2_UV_FLAG_REGISTER', 'FIDO2_UV_FLAG_LOGIN', 'WEBAUTHN_UV_FLAG_LOGIN', 'WEBAUTHN_UV_FLAG_REGISTER'];

    private ?string $memoFingerprint = null;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Repository $cache) {}

    /**
     * @phpstan-impure
     */
    public function sessionLifetime(): int
    {
        $value = $this->value('SESSION_LIFETIME');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : (int) config('restv2.vars_defaults.SESSION_LIFETIME');
    }

    /**
     * @phpstan-impure
     */
    public function defaultLanguage(): string
    {
        $value = $this->value('DEFAULT_LANG');

        return is_string($value) && $value !== '' ? $value : (string) config('restv2.vars_defaults.DEFAULT_LANG');
    }

    /**
     * @return array<string, string> language code => display name
     *
     * @phpstan-impure
     */
    public function availableLanguages(): array
    {
        $value = $this->value('AVAILABLE_LANGUAGES');

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            $value = (array) config('restv2.vars_defaults.AVAILABLE_LANGUAGES');
        }

        return array_map('strval', $value);
    }

    /**
     * @phpstan-impure
     */
    public function fido2UvRegister(): bool|string
    {
        return $this->flagOrString('FIDO2_UV_FLAG_REGISTER');
    }

    /**
     * @phpstan-impure
     */
    public function fido2UvLogin(): bool|string
    {
        return $this->flagOrString('FIDO2_UV_FLAG_LOGIN');
    }

    /**
     * @phpstan-impure
     */
    public function fido2UserPresent(): bool
    {
        return (bool) $this->value('FIDO2_USER_PRESENT_FLAG');
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    public function fido2Formats(): array
    {
        $value = $this->value('FIDO2_FORMATS');

        return is_array($value) && $value !== []
            ? array_values(array_map('strval', $value))
            : (array) config('restv2.vars_defaults.FIDO2_FORMATS');
    }

    /**
     * @phpstan-impure
     */
    public function webauthnUvLogin(): bool|string
    {
        return $this->flagOrString('WEBAUTHN_UV_FLAG_LOGIN');
    }

    /**
     * @phpstan-impure
     */
    public function webauthnUserPresent(): bool
    {
        return (bool) $this->value('WEBAUTHN_USER_PRESENT_FLAG');
    }

    /**
     * @phpstan-impure
     */
    public function otpLabel(): string
    {
        $value = $this->value('OTP_LABEL');

        return is_string($value) ? $value : (string) config('restv2.vars_defaults.OTP_LABEL');
    }

    /**
     * mailcow's built-in apps ($MAILCOW_APPS): name, link and optionally user_link, hide and
     * description.
     *
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    public function mailcowApps(): array
    {
        $value = $this->value('MAILCOW_APPS');

        if (! is_array($value)) {
            return (array) config('restv2.vars_defaults.MAILCOW_APPS');
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Maximum number of tags per domain or mailbox (legacy `$TAGGING_LIMIT`, feature 002 FR-043).
     *
     * @phpstan-impure
     */
    public function taggingLimit(): int
    {
        $value = $this->value('TAGGING_LIMIT');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : (int) config('restv2.vars_defaults.TAGGING_LIMIT');
    }

    /**
     * Seconds that a call of mailcow's container service may take (legacy `$DOCKER_TIMEOUT`).
     *
     * @phpstan-impure
     */
    public function dockerTimeout(): int
    {
        $value = $this->value('DOCKER_TIMEOUT');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : (int) config('restv2.vars_defaults.DOCKER_TIMEOUT');
    }

    /**
     * Whether last logins are reported (legacy `$SHOW_LAST_LOGIN`, feature 003 FR-010).
     *
     * @phpstan-impure
     */
    public function showLastLogin(): bool
    {
        $value = $this->value('SHOW_LAST_LOGIN');

        return is_bool($value) ? $value : (bool) config('restv2.vars_defaults.SHOW_LAST_LOGIN');
    }

    /**
     * Default attributes of new mailboxes (legacy `$MAILBOX_DEFAULT_ATTRIBUTES`, feature 003
     * research R5): the operator's values merged over the upstream ones, so that a partial
     * override keeps every other default.
     *
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    public function mailboxDefaultAttributes(): array
    {
        $defaults = (array) config('restv2.vars_defaults.MAILBOX_DEFAULT_ATTRIBUTES');
        $value = $this->value('MAILBOX_DEFAULT_ATTRIBUTES');

        return is_array($value) && ! array_is_list($value) ? array_merge($defaults, $value) : $defaults;
    }

    /**
     * Whether external alias destinations are refused for every role, administrators included
     * (legacy `$ALIAS_DISABLE_EXTERNAL_DOMAINS`, feature 004 FR-007). Off by default.
     *
     * @phpstan-impure
     */
    public function aliasDisableExternalDomains(): bool
    {
        return $this->value('ALIAS_DISABLE_EXTERNAL_DOMAINS') === true;
    }

    /**
     * The effective value of a legacy variable listed in config/restv2.php.
     *
     * @phpstan-impure
     */
    public function value(string $name): mixed
    {
        $override = config("restv2.vars_overrides.{$name}");

        if (is_string($override) && $override !== '') {
            $parsed = $this->parseOverride($name, $override);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        $exported = $this->exported();

        if (array_key_exists($name, $exported)) {
            return $exported[$name];
        }

        return config("restv2.vars_defaults.{$name}");
    }

    private function flagOrString(string $name): bool|string
    {
        $value = $this->value($name);

        return is_bool($value) ? $value : strtolower((string) $value);
    }

    /**
     * Converts a RESTV2_<NAME> string to the type of the variable's upstream default; null when it
     * cannot be converted (the setting is then ignored with a warning).
     */
    private function parseOverride(string $name, string $raw): mixed
    {
        $default = config("restv2.vars_defaults.{$name}");
        $bool = match (strtolower(trim($raw))) {
            'true', '1', 'yes', 'y', 'on' => true,
            'false', '0', 'no', 'n', 'off' => false,
            default => null,
        };

        $parsed = match (true) {
            in_array($name, self::FLAG_OR_STRING, true) => $bool ?? strtolower(trim($raw)),
            is_bool($default) => $bool,
            is_int($default) => is_numeric($raw) ? (int) $raw : null,
            is_array($default) => is_array($decoded = json_decode($raw, true)) ? $decoded : null,
            default => $raw,
        };

        if ($parsed === null) {
            Log::warning("Ignoring the server setting RESTV2_{$name}: it is not a valid value.");
        }

        return $parsed;
    }

    /**
     * The variables evaluated from the mounted files, or [] when they are missing or invalid.
     *
     * @return array<string, mixed>
     */
    private function exported(): array
    {
        $dir = $this->directory();
        $fingerprint = $this->fingerprint($dir);

        if ($fingerprint === $this->memoFingerprint) {
            return $this->memo;
        }

        $key = self::CACHE_PREFIX.$fingerprint;
        $cached = $this->cacheGet($key);

        if (is_array($cached) && array_key_exists('ok', $cached)) {
            $values = $cached['ok'] === true && is_array($cached['values'] ?? null) ? $cached['values'] : [];
        } else {
            [$ok, $values, $reason] = $this->export($dir);

            if (! $ok) {
                Log::warning("mailcow configuration not read ({$reason}); upstream defaults apply. Mount mailcow's data/web/inc at RESTV2_MAILCOW_INC_DIR or set RESTV2_* server settings.");
            }

            $this->cachePut($key, ['ok' => $ok, 'values' => $values]);
        }

        $this->memoFingerprint = $fingerprint;

        return $this->memo = $values;
    }

    private function directory(): ?string
    {
        $dir = trim((string) config('restv2.mailcow_inc_dir'));

        if ($dir === '') {
            return null;
        }

        return str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
    }

    private function fingerprint(?string $dir): string
    {
        $names = $this->names();
        $parts = ['dir' => $dir, 'names' => $names, 'host' => $this->hostname()];

        if ($dir !== null) {
            clearstatcache(true);

            foreach (['vars.inc.php', 'vars.local.inc.php'] as $file) {
                $stat = @stat($dir.'/'.$file);
                $parts[$file] = $stat === false ? null : [$stat['size'], $stat['mtime'], $stat['ino']];
            }
        }

        return hash('sha256', (string) json_encode($parts));
    }

    /**
     * @return array{bool, array<string, mixed>, string}
     */
    private function export(?string $dir): array
    {
        if ($dir === null || ! is_file($dir.'/vars.inc.php')) {
            return [false, [], 'directory '.($dir ?? '(not set)').' has no vars.inc.php'];
        }

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $process = new Process([
            $php, '-n', '-d', 'display_errors=stderr',
            resource_path('bin/export-mailcow-vars.php'),
            $dir,
            $this->hostname(),
            ...$this->names(),
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $e) {
            return [false, [], 'the export failed: '.$e->getMessage()];
        }

        if (! $process->isSuccessful()) {
            return [false, [], 'the export exited with '.$process->getExitCode().': '.trim(substr($process->getErrorOutput(), 0, 300))];
        }

        $values = json_decode($process->getOutput(), true);

        return is_array($values)
            ? [true, $values, '']
            : [false, [], 'the export printed no JSON object'];
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_values(array_unique([
            ...array_keys((array) config('restv2.vars_overrides')),
            ...array_keys((array) config('restv2.vars_defaults')),
        ]));
    }

    private function hostname(): string
    {
        return (string) (config('restv2.mailcow_hostname') ?: 'localhost');
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function cachePut(string $key, array $value): void
    {
        try {
            $this->cache->put($key, $value, self::CACHE_TTL);
        } catch (Throwable) {
            // Redis unavailable: the next call evaluates the files again.
        }
    }
}
