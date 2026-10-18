<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\IdentityProviderType;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Exceptions\ProblemRenderer;
use App\Exceptions\ValidationProblemException;
use App\Models\Mailcow\Mailbox;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\RefusalAuditor;
use App\Services\Mailboxes\MailboxCreator;
use App\Services\Mailboxes\MailboxDefaults;
use App\Services\Mailboxes\MailboxReader;
use App\Services\Mailboxes\MailboxUpdater;
use App\Services\Mailboxes\TaggedMailHandlingStore;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Database\NamedLock;
use App\Support\Http\ProblemType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * The only place where feature 012 changes mailboxes (research R6); every write is delegated to
 * feature 003's mailbox services (MailboxCreator, MailboxUpdater; the plan calls them
 * `MailboxService::create()` and `::update()`), acting for the system as legacy's
 * `access_all_exception` does, with the signing-in user as the audit actor.
 *
 *  - provision(): the mailbox of a user whom the provider authenticated and who has none, from the
 *    template the mapping value selects or else the default template (FR-034 to FR-040), under
 *    `GET_LOCK('restv2_idp_<sha1 of the address>', 10)` with a re-check, so that concurrent first
 *    sign-ins create one mailbox.
 *  - updateFromTemplate(): an existing mailbox takes its mapped template's values and the
 *    provider's name when the template marker or the name differ (FR-025); never from the default
 *    template. A failure is audited and never stops the sign-in.
 */
class MailboxProvisioner
{
    public const TRIGGER = 'identityProviderSignIn';

    public function __construct(
        private readonly MailboxCreator $creator,
        private readonly MailboxUpdater $updater,
        private readonly MailboxDefaults $defaults,
        private readonly NamedLock $lock,
        private readonly RateLimitStore $rateLimits,
        private readonly TaggedMailHandlingStore $tagged,
        private readonly AuditLogger $audit,
        private readonly RefusalAuditor $refusals,
    ) {}

    public function provision(IdentityProviderConfig $config, string $email, ?string $mappingValue, ?string $displayName, string $signInRequestId): ProvisioningResult
    {
        if (! $config->get('createMailboxOnSignIn')) {
            return ProvisioningResult::failed('autoCreationOff');
        }

        $template = $config->mappedTemplate($mappingValue) ?? $config->get('defaultTemplate');

        if (! is_string($template) || $template === '') {
            return ProvisioningResult::failed('noMatchingMapping');
        }

        $row = self::template($template);

        if ($row === null) {
            return ProvisioningResult::failed('mailboxCreationFailed', 'templateMissing', $template);
        }

        [$localPart, $domain] = explode('@', $email, 2);
        $name = self::name($displayName);

        return $this->lock->run(['restv2_idp_'.sha1($email)], function () use ($config, $email, $localPart, $domain, $name, $row, $template, $signInRequestId): ProvisioningResult {
            if (self::exists($email)) {
                return ProvisioningResult::existing($template);
            }

            $input = [
                'localPart' => $localPart,
                'domain' => $domain,
                'authSource' => $config->type->value,
                'templateId' => (int) $row->id,
            ];

            if ($name !== '') {
                $input['displayName'] = $name;
            }

            try {
                $this->creator->create(self::system(), $input, $this->options($email, $template, (string) $row->attributes, $signInRequestId));

                return ProvisioningResult::created($template);
            } catch (ProblemException $e) {
                if ($e->type === ProblemType::AddressInUse && self::exists($email)) {
                    return ProvisioningResult::existing($template);
                }

                return ProvisioningResult::failed('mailboxCreationFailed', match ($e->type) {
                    ProblemType::AddressInUse => 'addressInUse',
                    ProblemType::MailboxLimitReached => 'mailboxLimitReached',
                    ProblemType::TemporaryFailure => 'temporaryFailure',
                    default => 'validationFailed',
                }, $template);
            } catch (ValidationProblemException $e) {
                return ProvisioningResult::failed('mailboxCreationFailed', match (true) {
                    isset($e->errors['domain']) => 'domainNotFound',
                    isset($e->errors['templateId']) => 'templateMissing',
                    isset($e->errors['quota']) => 'quotaExceeded',
                    default => 'validationFailed',
                }, $template);
            } catch (SharedStoreUnavailable) {
                return ProvisioningResult::failed('mailboxCreationFailed', 'temporaryFailure', $template);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062 && self::exists($email)) {
                    return ProvisioningResult::existing($template);
                }

                throw $e;
            }
        }, 10);
    }

    /**
     * Applies the mapped template to an existing mailbox when needed. Returns whether it was
     * applied; null when no update was due or it failed (audited).
     */
    public function updateFromTemplate(IdentityProviderConfig $config, string $email, ?string $mappingValue, ?string $displayName, string $signInRequestId): bool
    {
        $template = $config->mappedTemplate($mappingValue);

        if ($template === null) {
            return false;
        }

        $row = self::template($template);

        if ($row === null) {
            // Legacy skips the update when the template is missing ("template_missing").
            $this->recordFailure($email, $template, $signInRequestId, 404, 'about:blank', 'notFound');

            return false;
        }

        $mailbox = DB::table('mailbox')->where('username', $email)->first(['name', 'local_part', 'attributes']);

        if (! $mailbox instanceof stdClass) {
            return false;
        }

        $marker = TemplateMarker::for((string) $row->attributes);
        $name = self::name($displayName);
        $effectiveName = $name === '' ? (string) $mailbox->local_part : $name;
        $storedMarker = MailboxReader::decode($mailbox->attributes)['attribute_hash'] ?? '';

        if ($storedMarker === $marker && (string) $mailbox->name === $effectiveName) {
            return false;
        }

        $values = $this->defaults->decodeTemplate($row->attributes);
        $patch = array_intersect_key($values, array_flip(['status', 'quota', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess', 'userPermissions', 'tags']));
        $patch['displayName'] = $effectiveName;

        if ($config->type !== IdentityProviderType::Keycloak && $config->type !== IdentityProviderType::Ldap) {
            // Generic OIDC mailboxes are never forced to set up a second factor (feature 003 FR-025).
            $patch['forceSecondFactorSetup'] = false;
        }

        $patch['forcePasswordChange'] = false;

        try {
            $this->updater->update(self::system(), $email, $patch, null, null, false, $this->options($email, $template, (string) $row->attributes, $signInRequestId));
            $this->applyFollowUps($email, $values);

            return true;
        } catch (Throwable $e) {
            $response = ProblemRenderer::render($e, request());
            $problem = (array) json_decode((string) $response->getContent(), true);

            if ($response->getStatusCode() >= 500) {
                Log::error("The template update of {$email} on sign-in failed: ".$e->getMessage());
            }

            $this->recordFailure($email, $template, $signInRequestId, $response->getStatusCode(), (string) ($problem['type'] ?? 'about:blank'), null, is_array($problem['errors'] ?? null) ? $problem['errors'] : null);

            return false;
        }
    }

    /**
     * The template's rate limit and tagged-mail handling, which legacy's update from a template
     * applies besides the mailbox values (ratelimit('edit'), mailbox('edit', 'delimiter_action')).
     *
     * @param  array<string, mixed>  $values  the template's decoded values
     */
    private function applyFollowUps(string $email, array $values): void
    {
        try {
            if (array_key_exists('rateLimit', $values)) {
                is_array($values['rateLimit'])
                    ? $this->rateLimits->set($email, (int) $values['rateLimit']['value'], (string) $values['rateLimit']['frame'])
                    : $this->rateLimits->delete($email);
            }

            if (is_string($values['taggedMailHandling'] ?? null)) {
                $this->tagged->set($email, $values['taggedMailHandling']);
            }
        } catch (Throwable $e) {
            Log::warning("The rate limit or tagged-mail handling of {$email} was not applied on sign-in: ".$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private function recordFailure(string $email, string $template, string $signInRequestId, int $status, string $problemType, ?string $reason = null, ?array $errors = null): void
    {
        $details = ['problemType' => $problemType, 'status' => $status, 'trigger' => self::TRIGGER, 'template' => $template, 'signInRequestId' => $signInRequestId];

        if ($errors !== null) {
            $details['errors'] = array_map(
                fn (mixed $violations): array => array_map(fn (mixed $v): array => ['code' => (string) ($v['code'] ?? ''), 'params' => $v['params'] ?? []], is_array($violations) ? $violations : []),
                $errors,
            );
        }

        DB::transaction(fn () => $this->audit->record(
            AuditEventType::MailboxUpdate,
            false,
            ['username' => $email, 'role' => null],
            ['type' => 'mailbox', 'id' => $email, 'name' => null],
            null,
            $reason ?? $this->refusals->reasonFor($status, $problemType),
            $details,
        ));
    }

    /**
     * @return array{templateMarker: string, auditActor: array{username: string, role: null}, auditDetails: array<string, mixed>}
     */
    private function options(string $email, string $template, string $attributes, string $signInRequestId): array
    {
        return [
            'templateMarker' => TemplateMarker::for($attributes),
            'auditActor' => ['username' => $email, 'role' => null],
            'auditDetails' => ['trigger' => self::TRIGGER, 'template' => $template, 'signInRequestId' => $signInRequestId],
        ];
    }

    /**
     * The system actor of creations and updates on sign-in: administrator-equivalent, so that no
     * domain administrator's flags apply (legacy `access_all_exception`).
     */
    private static function system(): Principal
    {
        return new Principal('identity-provider-sign-in', Role::Admin, AccountKind::Admin, AuthMethod::SingleSignOn);
    }

    private static function template(string $name): ?stdClass
    {
        $row = DB::table('templates')->where('type', 'mailbox')->where('template', $name)->first(['id', 'template', 'attributes']);

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @phpstan-impure
     */
    private static function exists(string $email): bool
    {
        return Mailbox::query()->where('username', $email)->exists();
    }

    /**
     * The provider's display name as legacy strips it (`ltrim(rtrim($name, '>'), '<')`).
     */
    private static function name(?string $name): string
    {
        return trim(ltrim(rtrim((string) $name, '>'), '<'));
    }
}
