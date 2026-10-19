<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Policies\QuarantinePolicy;
use App\Services\Access\SpamFilterScope;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * A mailbox's quarantine notification settings (feature 006 research R9, FR-035 to FR-038): the
 * `quarantine_notification` (never, hourly, daily, weekly) and `quarantine_category` (reject,
 * add_header, all) attributes the legacy notifier reads. A change locks the mailbox row, compares
 * `If-Match`, refuses a mailbox user's change of a member whose flag they lack
 * (`attribute-not-permitted`; a member sent with its current value is no change), runs legacy's
 * exact `JSON_SET` statements and writes the audit record in the same transaction. The time of the
 * last notification and the items' notification state are never touched.
 */
class QuarantineNotificationService
{
    public const FREQUENCIES = ['never', 'hourly', 'daily', 'weekly'];

    /** Stored category => contract category. */
    public const CATEGORIES = ['reject' => 'rejected', 'add_header' => 'junk', 'all' => 'all'];

    public function __construct(
        private readonly SpamFilterScope $scope,
        private readonly QuarantinePolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{mailbox: string, frequency: string, category: string|null, etag: string}
     */
    public function read(Principal $principal, string $rawMailbox): array
    {
        $this->policy->authorize($principal, QuarantinePolicy::VIEW_NOTIFICATIONS);
        $mailbox = $this->scope->mailbox($principal, $rawMailbox);

        return self::present($mailbox, $this->row($mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.'));
    }

    /**
     * Applies the members sent (`frequency`, `category`) to a mailbox the caller reaches.
     *
     * @param  array{frequency?: string, category?: string}  $patch
     * @return array{mailbox: string, frequency: string, category: string|null, etag: string}
     */
    public function update(Principal $principal, string $mailbox, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): array
    {
        return DB::transaction(function () use ($principal, $mailbox, $patch, $ifMatch, $bulkRequestId): array {
            $row = $this->row($mailbox, lock: true) ?? throw new NotFoundHttpException('The mailbox does not exist.');
            $current = self::present($mailbox, $row);

            if (ETag::preconditionFails($ifMatch, $current['etag'])) {
                throw new PreconditionFailedHttpException('The notification settings have changed since they were read.');
            }

            $changes = [];

            foreach (['frequency', 'category'] as $member) {
                if (array_key_exists($member, $patch) && $patch[$member] !== $current[$member]) {
                    $changes[$member] = ['old' => $current[$member], 'new' => $patch[$member]];
                }
            }

            $this->policy->authorizeNotificationChange($principal, array_keys($changes));

            if (isset($changes['frequency'])) {
                DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.quarantine_notification', ?) WHERE `username` = ?", [$changes['frequency']['new'], $mailbox]);
            }

            if (isset($changes['category'])) {
                DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.quarantine_category', ?) WHERE `username` = ?", [(string) array_search($changes['category']['new'], self::CATEGORIES, true), $mailbox]);
            }

            $this->audit->record(
                AuditEventType::QuarantineNotificationSettingsUpdate,
                true,
                $principal,
                ['type' => 'quarantineNotificationSettings', 'id' => $mailbox, 'name' => $mailbox],
                null,
                null,
                array_filter(['changes' => $changes, 'bulkRequestId' => $bulkRequestId], fn (mixed $v): bool => $v !== null),
            );

            return self::present($mailbox, $this->row($mailbox) ?? throw new NotFoundHttpException('The mailbox does not exist.'));
        });
    }

    private function row(string $mailbox, bool $lock = false): ?stdClass
    {
        $query = DB::table('mailbox')
            ->where('username', $mailbox)
            ->where('kind', '')
            ->select([
                DB::raw("JSON_VALUE(`attributes`, '$.quarantine_notification') AS `notification`"),
                DB::raw("JSON_VALUE(`attributes`, '$.quarantine_category') AS `category`"),
            ]);

        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @return array{mailbox: string, frequency: string, category: string|null, etag: string}
     */
    private static function present(string $mailbox, stdClass $row): array
    {
        $notification = is_string($row->notification) ? $row->notification : null;
        $category = is_string($row->category) ? $row->category : null;

        return [
            'mailbox' => $mailbox,
            'frequency' => in_array($notification, self::FREQUENCIES, true) ? (string) $notification : 'never',
            'category' => self::CATEGORIES[(string) $category] ?? null,
            'etag' => ETag::of(['quarantineNotificationSettings', $mailbox, $notification, $category]),
        ];
    }
}
