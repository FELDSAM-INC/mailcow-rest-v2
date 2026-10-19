<?php

namespace App\Services\Settings;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\Log;
use Redis;

/**
 * The password reset e-mail (feature 013 FR-039 to FR-042, research R9) in legacy's keys
 * `PW_RESET_FROM`, `PW_RESET_SUBJ`, `PW_RESET_TEXT` and `PW_RESET_HTML`, which feature 005's reset
 * reads. While both templates are empty, mailcow's default templates (`pw_reset_text.tpl` and
 * `pw_reset_html.tpl` of the mounted `data/assets/templates`, feature 005's
 * `RESTV2_MAILCOW_TPLS_DIR`) apply and are returned. Password reset is available only while both a
 * sender and a subject are set, as legacy decides.
 */
class PasswordResetNotificationService
{
    public const KEYS = ['PW_RESET_FROM', 'PW_RESET_SUBJ', 'PW_RESET_TEXT', 'PW_RESET_HTML'];

    private const MEMBERS = ['sender' => 'PW_RESET_FROM', 'subject' => 'PW_RESET_SUBJ', 'textTemplate' => 'PW_RESET_TEXT', 'htmlTemplate' => 'PW_RESET_HTML'];

    private const TEMPLATES = ['textTemplate', 'htmlTemplate'];

    public function __construct(
        private readonly RedisSettingsStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Contract `PasswordResetNotification`.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return self::present($this->store->strings(self::KEYS));
    }

    /**
     * @param  array<string, string>  $patch  validated members
     * @return array<string, mixed>
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->store->transaction(
            self::KEYS,
            $ifMatch,
            fn (Redis $redis): array => RedisSettingsStore::stringsFrom($redis, self::KEYS),
            fn (array $raw): string => ETag::of(['passwordResetNotification', ...array_values($raw)]),
            function (array $raw) use ($patch): ?SettingsWrite {
                $writes = [];
                $changes = [];

                foreach (self::MEMBERS as $member => $key) {
                    $old = (string) ($raw[$key] ?? '');

                    if (! array_key_exists($member, $patch) || $patch[$member] === $old) {
                        continue;
                    }

                    $writes[$key] = $patch[$member];
                    $changes[$member] = in_array($member, self::TEMPLATES, true)
                        ? ['changed' => true, 'oldBytes' => strlen($old), 'newBytes' => strlen($patch[$member])]
                        : ['old' => $old, 'new' => $patch[$member]];
                }

                if ($writes === []) {
                    return null;
                }

                return new SettingsWrite(
                    function (Redis $multi) use ($writes): void {
                        foreach ($writes as $key => $value) {
                            $multi->set($key, $value);
                        }
                    },
                    function (Redis $multi) use ($raw): void {
                        foreach ($raw as $key => $value) {
                            $value === null ? $multi->del($key) : $multi->set($key, $value);
                        }
                    },
                    $changes,
                );
            },
            fn (mixed $changes) => $this->audit->record(AuditEventType::PasswordResetNotificationUpdate, true, $actor, ['type' => 'passwordResetNotification', 'id' => null, 'name' => null], null, null, ['changes' => $changes]),
        );

        return $this->read();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, mixed>
     */
    private static function present(array $raw): array
    {
        $sender = (string) ($raw['PW_RESET_FROM'] ?? '');
        $subject = (string) ($raw['PW_RESET_SUBJ'] ?? '');
        $text = (string) ($raw['PW_RESET_TEXT'] ?? '');
        $html = (string) ($raw['PW_RESET_HTML'] ?? '');
        $defaults = $text === '' && $html === '';

        return [
            'sender' => $sender,
            'subject' => $subject,
            'textTemplate' => $defaults ? self::template('pw_reset_text.tpl') : $text,
            'htmlTemplate' => $defaults ? self::template('pw_reset_html.tpl') : $html,
            'templatesAreDefault' => $defaults,
            'passwordResetAvailable' => $sender !== '' && $subject !== '',
            'etag' => ETag::of(['passwordResetNotification', ...array_values($raw)]),
        ];
    }

    /**
     * A default template of mailcow's mounted template directory; empty (with a warning) when it
     * cannot be read.
     */
    public static function template(string $file): string
    {
        $dir = (string) config('restv2.mailcow_tpls_dir');
        $dir = str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
        $content = @file_get_contents($dir.'/'.$file);

        if (! is_string($content)) {
            Log::warning("mailcow's default template {$file} cannot be read from {$dir}.");

            return '';
        }

        return $content;
    }
}
