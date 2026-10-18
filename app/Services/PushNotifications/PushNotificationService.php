<?php

namespace App\Services\PushNotifications;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Clients\Pushover\PushoverClient;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\SelfService\PermissionGate;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * A mailbox's Pushover notifications (feature 005 FR-054–FR-057, research R14), stored in
 * `pushover` exactly as legacy pushover('edit') stores them (REPLACE INTO, attributes with string
 * flags and the sound, senders comma-separated with dots removed from `@gmail.com` local parts), so
 * that mailcow's notifier uses them unchanged. The token and the user key are write-only: reads
 * say only whether they are set, a replacement that omits them keeps them, and the version tag
 * carries only HMACs of them. Changes and verifications need `pushover` (domain administrators:
 * their own flag); reads need none.
 */
class PushNotificationService
{
    public const DEFAULT_SOUND = 'pushover';

    public function __construct(
        private readonly PermissionGate $gate,
        private readonly PushoverClient $pushover,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed> contract `PushNotificationSettings`
     */
    public function read(string $mailbox): array
    {
        return $this->present($this->row($mailbox));
    }

    /**
     * Replaces the settings as a whole; omitted credentials keep the stored ones.
     *
     * @param  array<string, mixed>  $input  validated `PushNotificationSettingsReplacement` members
     * @return array<string, mixed>
     */
    public function replace(Request $request, Principal $principal, string $mailbox, array $input): array
    {
        $this->gate->requireChange($principal, PermissionFlag::Pushover);

        return DB::transaction(function () use ($request, $principal, $mailbox, $input): array {
            $row = $this->row($mailbox, true);

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The push notification settings have changed.');
            }

            $violations = new FieldViolations;
            $token = isset($input['token']) ? (string) $input['token'] : (is_object($row) ? (string) $row->token : '');
            $key = isset($input['userKey']) ? (string) $input['userKey'] : (is_object($row) ? (string) $row->key : '');

            foreach (['token' => $token, 'userKey' => $key] as $field => $value) {
                if ($value === '') {
                    $violations->add($field, 'secretRequired', ['reason' => 'noStoredSecret'], 'Required until a value is stored.');
                }
            }

            $violations->throwIfAny();

            $new = [
                'title' => (string) ($input['title'] ?? ''),
                'text' => (string) ($input['text'] ?? ''),
                'senders' => self::storedSenders((array) ($input['senders'] ?? [])),
                'senders_regex' => (string) ($input['senderPattern'] ?? ''),
                'active' => ($input['active'] ?? true) !== false,
                'evaluate_x_prio' => ($input['evaluatePriority'] ?? false) === true,
                'only_x_prio' => ($input['onlyHighPriority'] ?? false) === true,
                'sound' => (string) ($input['sound'] ?? self::DEFAULT_SOUND),
            ];
            $old = $this->present($row);

            DB::statement('REPLACE INTO `pushover` (`username`, `key`, `attributes`, `senders_regex`, `senders`, `token`, `title`, `text`, `active`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $mailbox,
                $key,
                json_encode(['evaluate_x_prio' => (string) (int) $new['evaluate_x_prio'], 'only_x_prio' => (string) (int) $new['only_x_prio'], 'sound' => $new['sound']]),
                $new['senders_regex'],
                $new['senders'],
                $token,
                $new['title'],
                $new['text'],
                (int) $new['active'],
            ]);

            $current = $this->present($this->row($mailbox));
            $changes = [];

            foreach (['active', 'senders', 'senderPattern', 'sound', 'evaluatePriority', 'onlyHighPriority'] as $field) {
                if ($old[$field] !== $current[$field]) {
                    $changes[$field] = ['old' => $old[$field], 'new' => $current[$field]];
                }
            }

            foreach (['title', 'text'] as $field) {
                if ($old[$field] !== $current[$field]) {
                    $changes[$field] = ['changed' => true, 'oldSize' => mb_strlen($old[$field]), 'newSize' => mb_strlen($current[$field])];
                }
            }

            foreach (['token' => $row?->token, 'userKey' => $row?->key] as $field => $stored) {
                $value = $field === 'token' ? $token : $key;

                if ($stored === null || $stored === '') {
                    $changes[$field] = 'set';
                } elseif (! hash_equals((string) $stored, $value)) {
                    $changes[$field] = 'changed';
                }
            }

            $this->audit->record(AuditEventType::PushNotificationSettingsUpdate, true, $principal, ['type' => 'pushNotificationSettings', 'id' => $mailbox, 'name' => null], null, null, [
                'changes' => $changes,
                'created' => $row === null,
            ]);

            return $current;
        });
    }

    public function delete(Request $request, Principal $principal, string $mailbox): void
    {
        $this->gate->requireChange($principal, PermissionFlag::Pushover);

        DB::transaction(function () use ($request, $principal, $mailbox): void {
            $row = $this->row($mailbox, true);

            if (ETag::ifMatchFails($request, $this->etag($row))) {
                throw new PreconditionFailedHttpException('The push notification settings have changed.');
            }

            DB::table('pushover')->where('username', $mailbox)->delete();
            $this->audit->record(AuditEventType::PushNotificationSettingsDeletion, true, $principal, ['type' => 'pushNotificationSettings', 'id' => $mailbox, 'name' => null], null, null, ['removed' => $row !== null]);
        });
    }

    /**
     * @return array{accepted: bool, reason: string|null}
     */
    public function verify(Principal $principal, string $mailbox): array
    {
        $this->gate->requireChange($principal, PermissionFlag::Pushover);
        $row = $this->row($mailbox);

        if ($row === null || (string) $row->token === '' || (string) $row->key === '') {
            throw new ProblemException(ProblemType::PushNotificationsNotConfigured);
        }

        $verdict = $this->pushover->validate((string) $row->token, (string) $row->key);

        if ($verdict->unavailable) {
            throw new ProblemException(ProblemType::TemporaryFailure, 'Pushover cannot be reached. Retry later.', ['dependency' => 'pushover'], ['Retry-After' => '30']);
        }

        DB::transaction(fn () => $this->audit->record(AuditEventType::PushNotificationVerification, true, $principal, ['type' => 'pushNotificationSettings', 'id' => $mailbox, 'name' => null], null, null, ['accepted' => $verdict->accepted]));

        return ['accepted' => $verdict->accepted, 'reason' => $verdict->accepted ? null : $verdict->reason];
    }

    /**
     * Legacy's stored form of the senders: comma-separated, dots removed from the local part of
     * `@gmail.com` addresses (legacy's `preg_replace('/\.(?=.*?@gmail\.com$)/', …)`).
     *
     * @param  array<mixed>  $senders
     */
    public static function storedSenders(array $senders): string
    {
        $stored = [];

        foreach ($senders as $sender) {
            $sender = trim((string) $sender);

            if ($sender !== '') {
                $stored[] = (string) preg_replace('/\.(?=.*?@gmail\.com$)/', '', $sender);
            }
        }

        return implode(',', $stored);
    }

    private function row(string $mailbox, bool $lock = false): ?object
    {
        $query = DB::table('pushover')->where('username', $mailbox);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?object $row): array
    {
        $attributes = $row === null ? [] : json_decode((string) $row->attributes, true);
        $attributes = is_array($attributes) ? $attributes : [];
        $flag = fn (string $name): bool => is_scalar($attributes[$name] ?? null) && (int) $attributes[$name] === 1;
        $senders = $row === null || (string) $row->senders === '' ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $row->senders)), fn (string $s): bool => $s !== ''));

        return [
            'configured' => $row !== null && (string) $row->key !== '' && (string) $row->token !== '',
            'active' => $row === null || (int) $row->active !== 0,
            'tokenSet' => $row !== null && (string) $row->token !== '',
            'userKeySet' => $row !== null && (string) $row->key !== '',
            'title' => $row === null ? '' : (string) $row->title,
            'text' => $row === null ? '' : (string) $row->text,
            'senders' => $senders,
            'senderPattern' => $row === null ? '' : (string) $row->senders_regex,
            'sound' => is_string($attributes['sound'] ?? null) && $attributes['sound'] !== '' ? $attributes['sound'] : self::DEFAULT_SOUND,
            'evaluatePriority' => $flag('evaluate_x_prio'),
            'onlyHighPriority' => $flag('only_x_prio'),
            'etag' => $this->etag($row),
        ];
    }

    private function etag(?object $row): string
    {
        if ($row === null) {
            return ETag::of(['pushNotificationSettings', 'unconfigured']);
        }

        $hmac = fn (string $value): string => hash_hmac('sha256', $value, (string) config('app.key'));

        return ETag::of(['pushNotificationSettings', (string) $row->username, $hmac((string) $row->key), $hmac((string) $row->token), (string) $row->title, (string) $row->text, (string) $row->senders, (string) $row->senders_regex, (string) $row->attributes, (int) $row->active]);
    }
}
