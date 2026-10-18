<?php

namespace App\Services\MailSettings;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Audit\AuditLogger;
use App\Services\Mailboxes\MailboxValues;
use App\Services\Mailboxes\TaggedMailHandlingStore;
use App\Services\SelfService\PermissionGate;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * A mailbox's mail settings (feature 005 FR-058–FR-060, research R15): tagged-mail handling in
 * mailcow's shared store (`RCPT_WANTS_SUBJECT_TAG` / `RCPT_WANTS_SUBFOLDER_TAG`, through 003's
 * TaggedMailHandlingStore; subject wins when both are set, as legacy reads them) and inbound and
 * outbound TLS enforcement in `mailbox.attributes` (integers, as legacy mailbox('edit',
 * 'tls_policy') writes them). Changing the tagging needs `delimiterAction`, TLS needs `tlsPolicy`;
 * a value equal to the current one is no change. The database change and its audit record share a
 * transaction with the shared-store write last; a shared-store failure rolls everything back, and
 * a failed commit restores the shared store.
 */
class MailSettingsService
{
    public function __construct(
        private readonly TaggedMailHandlingStore $tagging,
        private readonly PermissionGate $gate,
        private readonly AuditLogger $audit,
        private readonly ?SharedStore $store = null,
    ) {}

    /**
     * @return array{mailbox: string, taggedMailHandling: string, tlsEnforceInbound: bool, tlsEnforceOutbound: bool, etag: string}
     *
     * @throws SharedStoreUnavailable
     */
    public function read(string $mailbox): array
    {
        $raw = DB::table('mailbox')->where('username', $mailbox)->value('attributes');

        if (! is_string($raw)) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        $attributes = json_decode($raw, true);
        $attributes = is_array($attributes) ? $attributes : [];
        $settings = [
            'mailbox' => $mailbox,
            'taggedMailHandling' => $this->taggingOf($mailbox),
            'tlsEnforceInbound' => MailboxValues::flag($attributes['tls_enforce_in'] ?? null) ?? false,
            'tlsEnforceOutbound' => MailboxValues::flag($attributes['tls_enforce_out'] ?? null) ?? false,
        ];

        return $settings + ['etag' => self::etag($settings)];
    }

    /**
     * Applies the members sent and returns the new settings.
     *
     * @param  array{taggedMailHandling?: string, tlsEnforceInbound?: bool, tlsEnforceOutbound?: bool}  $patch
     * @return array{mailbox: string, taggedMailHandling: string, tlsEnforceInbound: bool, tlsEnforceOutbound: bool, etag: string}
     */
    public function update(Principal $principal, string $mailbox, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): array
    {
        $current = $this->read($mailbox);

        if (ETag::preconditionFails($ifMatch, $current['etag'])) {
            throw new PreconditionFailedHttpException('The mail settings have changed.');
        }

        $changes = [];

        foreach (['taggedMailHandling', 'tlsEnforceInbound', 'tlsEnforceOutbound'] as $key) {
            if (array_key_exists($key, $patch) && $patch[$key] !== $current[$key]) {
                $changes[$key] = ['old' => $current[$key], 'new' => $patch[$key]];
            }
        }

        $this->gate->requireChange($principal, PermissionFlag::DelimiterAction, isset($changes['taggedMailHandling']));
        $this->gate->requireChange($principal, PermissionFlag::TlsPolicy, isset($changes['tlsEnforceInbound']) || isset($changes['tlsEnforceOutbound']));

        $undo = null;

        try {
            DB::transaction(function () use ($principal, $mailbox, $patch, $current, $changes, $bulkRequestId, &$undo): void {
                if (isset($changes['tlsEnforceInbound']) || isset($changes['tlsEnforceOutbound'])) {
                    DB::update(
                        "UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.tls_enforce_out', ?), `attributes` = JSON_SET(`attributes`, '$.tls_enforce_in', ?) WHERE `username` = ?",
                        [(int) ($patch['tlsEnforceOutbound'] ?? $current['tlsEnforceOutbound']), (int) ($patch['tlsEnforceInbound'] ?? $current['tlsEnforceInbound']), $mailbox],
                    );
                }

                $this->audit->record(
                    AuditEventType::MailSettingsUpdate,
                    true,
                    $principal,
                    ['type' => 'mailSettings', 'id' => $mailbox, 'name' => null],
                    null,
                    null,
                    array_filter(['changes' => $changes, 'bulkRequestId' => $bulkRequestId], fn (mixed $v): bool => $v !== null),
                );

                if (isset($changes['taggedMailHandling'])) {
                    $previous = $current['taggedMailHandling'];
                    $this->tagging->set($mailbox, (string) $patch['taggedMailHandling']);
                    $undo = fn () => $this->tagging->set($mailbox, $previous);
                }
            });
        } catch (Throwable $e) {
            if ($undo !== null) {
                try {
                    $undo();
                } catch (Throwable) {
                    // The shared store failed again; the error below is reported.
                }
            }

            throw $e;
        }

        return $this->read($mailbox);
    }

    /**
     * @param  array{taggedMailHandling: string, tlsEnforceInbound: bool, tlsEnforceOutbound: bool}  $settings
     */
    public static function etag(array $settings): string
    {
        return ETag::of(['mailSettings', $settings['taggedMailHandling'], $settings['tlsEnforceInbound'], $settings['tlsEnforceOutbound']]);
    }

    /**
     * @throws SharedStoreUnavailable
     */
    private function taggingOf(string $mailbox): string
    {
        $store = $this->store ?? app(SharedStore::class);

        // Subject first, as legacy reads them; a present field counts, as 003's mailbox read does
        // (legacy only ever writes `1`).
        if ($store->hasField(TaggedMailHandlingStore::SUBJECT, $mailbox)) {
            return 'subject';
        }

        return $store->hasField(TaggedMailHandlingStore::SUBFOLDER, $mailbox) ? 'subfolder' : 'none';
    }
}
