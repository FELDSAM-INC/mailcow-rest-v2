<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Models\Mailcow\SchemaVersion;
use App\Services\MailSettings\MailSettingsService;
use App\Services\Quarantine\QuarantineNotificationService;
use App\Services\RateLimits\RateLimitStore;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The same template settings apply to IdP mappings and effective mailbox-group templates. */
final class MailboxTemplateApplication
{
    public function __construct(private readonly MailboxDefaults $defaults, private readonly MailboxUpdater $updater,
        private readonly RateLimitStore $rateLimits, private readonly TaggedMailHandlingStore $tagged) {}

    /** @param array{templateName?: string, templateMarker?: string, auditActor?: array{username: string|null, role: null}, auditDetails?: array<string, mixed>} $options */
    public function apply(Principal $system, string $email, string $attributes, string $name, array $options, bool $allowSecondFactor = true): void
    {
        $values = $this->defaults->decodeTemplate($attributes);
        $patch = array_intersect_key($values, array_flip(['status', 'quota', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess', 'userPermissions', 'tags']));
        $patch['displayName'] = $name;
        $patch['forcePasswordChange'] = false;
        if (! $allowSecondFactor) {
            $patch['forceSecondFactorSetup'] = false;
        }
        $this->updater->update($system, $email, $patch, null, null, false, $options);
        try {
            if (SchemaVersion::hasNovember2026Features()) {
                $mailSettings = array_intersect_key($values, array_flip(['tlsEnforceInbound', 'tlsEnforceOutbound']));
                if ($mailSettings !== []) {
                    app(MailSettingsService::class)->update($system, $email, $mailSettings, null);
                }
                $notifications = [];
                foreach (['quarantineNotification' => 'frequency', 'quarantineCategory' => 'category'] as $field => $key) {
                    if (array_key_exists($field, $values)) {
                        $notifications[$key] = $values[$field];
                    }
                }
                if ($notifications !== []) {
                    app(QuarantineNotificationService::class)->update($system, $email, $notifications, null);
                }
            }
            if (array_key_exists('rateLimit', $values)) {
                is_array($values['rateLimit'])
                    ? $this->rateLimits->set($email, (int) $values['rateLimit']['value'], (string) $values['rateLimit']['frame'])
                    : $this->rateLimits->delete($email);
            }
            if (is_string($values['taggedMailHandling'] ?? null)) {
                $this->tagged->set($email, $values['taggedMailHandling']);
            }
        } catch (Throwable $e) {
            Log::warning('The mailbox template rate limit or tagged-mail handling could not be applied: '.$e->getMessage());
            throw $e;
        }
    }
}
