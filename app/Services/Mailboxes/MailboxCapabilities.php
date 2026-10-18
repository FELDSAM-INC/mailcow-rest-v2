<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Services\Config\MailcowConfig;
use App\Services\Identity\IdentityService;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Http\ListQuery;
use App\Support\Tags\TagSet;

/**
 * The mailbox capabilities (feature 003 FR-064, G31, G32): the server settings and limits a client
 * needs before it submits, computed from the current configuration on every request. No secret or
 * connection detail of the identity provider is ever read.
 */
class MailboxCapabilities
{
    public function __construct(
        private readonly MailcowConfig $config,
        private readonly MailboxDefaults $defaults,
        private readonly IdentityService $identity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Principal $principal): array
    {
        $defaults = $this->defaults->builtIn();
        $defaults['userPermissions'] = UserPermissions::visibleTo($defaults['userPermissions'], $principal, $this->identity->permissions($principal));

        return [
            'identityProviderType' => IdentityProviderType::current(),
            'authSources' => IdentityProviderType::allowedSources(),
            'groupwareEnabled' => ! config('restv2.skip_sogo'),
            'lastLoginsReported' => $this->config->showLastLogin(),
            'bulkLimit' => self::bulkLimit(),
            'taggingLimit' => $this->config->taggingLimit(),
            'tagMaxLength' => TagSet::MAX_LENGTH,
            'customAttributeLimit' => (int) config('restv2.mailbox_custom_attribute_limit', 100),
            'customAttributeNameMaxLength' => 255,
            'customAttributeValueMaxLength' => MailboxValidator::CUSTOM_ATTRIBUTE_VALUE_MAX,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
            'garbageRetentionMinutes' => (int) config('restv2.maildir_gc_time', 7200),
            'preHashedPasswordSchemes' => MailboxPasswordHasher::PRE_HASHED_SCHEMES,
            'templateDefaults' => $defaults,
        ];
    }

    /** Targets per bulk action: the server setting, at most 100. */
    public static function bulkLimit(): int
    {
        return max(1, min(100, (int) config('restv2.bulk_limit', 100)));
    }
}
