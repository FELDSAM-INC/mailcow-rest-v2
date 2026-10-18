<?php

namespace App\Services\Passkeys;

use App\Audit\AuditEventType;
use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\Fido2Credential;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PendingActionEvaluator;
use App\Services\Auth\SessionManager;
use App\Services\Config\MailcowConfig;
use App\Support\Http\Base64Url;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use App\Support\WebAuthn\OptionsJson;
use App\Support\WebAuthn\WebAuthnServerFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * The signed-in user's own passkeys (FR-052–FR-054, R9), stored in mailcow's `fido2` table exactly
 * as legacy `fido2('register')` stores them, so that they work in both UIs. This service is the
 * only writer of `fido2`. Every write and its audit record share one transaction.
 *
 * A passkey is identified by its owner and SHA2(credentialId, 256), the legacy `cid` and the
 * contract's `Passkey.id`. Its version tag covers the stored settings `id`, `friendlyName` and
 * `active`.
 */
class PasskeyService
{
    /** Sort values of `listPasskeys` and their columns. */
    public const SORT_COLUMNS = ['registeredAt' => 'created', 'name' => 'friendlyName'];

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly AuditLogger $audit,
        private readonly MailcowConfig $config,
        private readonly WebAuthnServerFactory $webauthn,
    ) {}

    /**
     * Issues registration options bound to the session (legacy `fido2-registration`): a
     * discoverable credential for this host, the account's passkeys excluded.
     *
     * @return array{publicKey: array<string, mixed>, expiresAt: CarbonImmutable}
     */
    public function issueRegistrationOptions(Request $request, Principal $principal): array
    {
        $excluded = DB::table('fido2')->where('username', $principal->username)->pluck('credentialId')->map(fn (mixed $id): string => (string) $id)->all();
        $server = $this->webauthn->make($request);
        $username = $principal->username;
        $args = $server->getCreateArgs($username, $username, $username, $this->ttl(), true, $this->config->fido2UvRegister(), null, $excluded);
        $expiresAt = $this->sessions->issue($request->session(), SessionManager::PASSKEY_REGISTRATION, $server->getChallenge()->getBinaryString(), [
            'username' => $username,
        ]);

        return ['publicKey' => OptionsJson::creation($args), 'expiresAt' => $expiresAt];
    }

    /**
     * Verifies the authenticator's response to the session's options and stores the passkey.
     *
     * @param  array<string, mixed>  $credential  a validated `WebAuthnRegistrationResponse`
     */
    public function register(Request $request, Principal $principal, array $credential, ?string $name): Fido2Credential
    {
        $options = $this->sessions->consume($request->session(), SessionManager::PASSKEY_REGISTRATION);

        if ($options === null || ($options['username'] ?? null) !== $principal->username) {
            $this->refuseRegistration($principal);
        }

        $response = (array) ($credential['response'] ?? []);

        try {
            $data = $this->webauthn->make($request)->processCreate(
                Base64Url::decode((string) ($response['clientDataJSON'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['attestationObject'] ?? '')) ?? '',
                $options['challenge'],
                $this->config->fido2UvRegister(),
                $this->config->fido2UserPresent(),
            );
        } catch (Throwable) {
            $this->refuseRegistration($principal);
        }

        $credentialId = (string) $data->credentialId;
        $id = hash('sha256', $credentialId);
        $lock = 'restv2_fido2_'.$id;
        $restrictedBefore = in_array(PendingAction::SecondFactorSetup, $this->pendingActions->evaluate($principal), true);

        // The credential ID must not exist yet; upstream has no index for it, so a named lock
        // guards the check and the insert (docs/conventions.md).
        DB::select('SELECT GET_LOCK(?, 10)', [$lock]);

        try {
            $stored = DB::transaction(function () use ($principal, $data, $credentialId, $id, $name, $restrictedBefore): bool {
                if (DB::table('fido2')->where('credentialId', $credentialId)->exists()) {
                    return false;
                }

                DB::table('fido2')->insert([
                    'username' => $principal->username,
                    'friendlyName' => $name,
                    'rpId' => (string) $data->rpId,
                    'credentialPublicKey' => (string) $data->credentialPublicKey,
                    'certificateChain' => $data->certificateChain,
                    'certificate' => $data->certificate,
                    'certificateIssuer' => $data->certificateIssuer,
                    'certificateSubject' => $data->certificateSubject,
                    'signatureCounter' => $data->signatureCounter,
                    'AAGUID' => $data->AAGUID,
                    'credentialId' => $credentialId,
                ]);

                $lifted = $restrictedBefore
                    && ! in_array(PendingAction::SecondFactorSetup, $this->pendingActions->evaluate($principal), true);

                $this->audit->record(AuditEventType::PasskeyRegistration, true, $principal, $this->target($id, $name), $principal->authMethod->value, null, [
                    'restrictionLifted' => $lifted,
                ]);

                return true;
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        if (! $stored) {
            $this->refuseRegistration($principal);
        }

        return $this->find($principal, $id) ?? throw new NotFoundHttpException;
    }

    /**
     * The account's passkeys as a list page.
     *
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(Principal $principal, ListQuery $query): array
    {
        $builder = $this->query($principal);
        $query->applySearch($builder, ['friendlyName', 'certificateSubject']);

        return $query->paginate($builder);
    }

    public function find(Principal $principal, string $id): ?Fido2Credential
    {
        return $this->query($principal)->whereRaw('SHA2(`credentialId`, 256) = ?', [$id])->first();
    }

    /**
     * Renames a passkey (legacy `fido2('edit_fn')`), checking `If-Match`.
     */
    public function rename(Request $request, Principal $principal, string $id, string $name): Fido2Credential
    {
        return DB::transaction(function () use ($request, $principal, $id, $name): Fido2Credential {
            $passkey = $this->lockedForChange($request, $principal, $id);

            if ($passkey->friendlyName !== $name) {
                DB::table('fido2')
                    ->where('username', $principal->username)
                    ->whereRaw('SHA2(`credentialId`, 256) = ?', [$id])
                    ->update(['friendlyName' => $name]);

                $this->audit->record(AuditEventType::PasskeyRename, true, $principal, $this->target($id, $name), $principal->authMethod->value, null, []);
            }

            return $this->find($principal, $id) ?? throw new NotFoundHttpException;
        });
    }

    /**
     * Removes a passkey (legacy `fido2('unset_fido2_key')`), checking `If-Match`. Removing the last
     * one is allowed: the password remains.
     */
    public function remove(Request $request, Principal $principal, string $id): void
    {
        DB::transaction(function () use ($request, $principal, $id): void {
            $passkey = $this->lockedForChange($request, $principal, $id);

            DB::table('fido2')
                ->where('username', $principal->username)
                ->whereRaw('SHA2(`credentialId`, 256) = ?', [$id])
                ->delete();

            $this->audit->record(AuditEventType::PasskeyRemoval, true, $principal, $this->target($id, $passkey->friendlyName), $principal->authMethod->value, null, []);
        });
    }

    /**
     * The version tag of a passkey's stored settings.
     */
    public static function etag(Fido2Credential $passkey): string
    {
        return ETag::of([(string) $passkey->getAttribute('cid'), $passkey->friendlyName, (bool) $passkey->active]);
    }

    /**
     * @return Builder<Fido2Credential>
     */
    private function query(Principal $principal): Builder
    {
        return Fido2Credential::query()
            ->select(['friendlyName', 'certificateSubject', 'created', 'rpId', 'active'])
            ->selectRaw('SHA2(`credentialId`, 256) AS `cid`')
            ->where('username', $principal->username);
    }

    private function lockedForChange(Request $request, Principal $principal, string $id): Fido2Credential
    {
        $passkey = $this->query($principal)->whereRaw('SHA2(`credentialId`, 256) = ?', [$id])->lockForUpdate()->first();

        if ($passkey === null) {
            throw new NotFoundHttpException('The passkey does not exist.');
        }

        if (ETag::ifMatchFails($request, self::etag($passkey))) {
            throw new PreconditionFailedHttpException('The passkey was changed since it was read.');
        }

        return $passkey;
    }

    /**
     * Audits the refused registration in its own transaction and answers
     * `passkey-registration-failed`.
     */
    private function refuseRegistration(Principal $principal): never
    {
        DB::transaction(fn () => $this->audit->record(
            AuditEventType::PasskeyRegistration,
            false,
            $principal,
            null,
            $principal->authMethod->value,
            'registrationFailed',
            [],
        ));

        throw new ProblemException(ProblemType::PasskeyRegistrationFailed);
    }

    /**
     * @return array{type: string, id: string, name: string|null}
     */
    private function target(string $id, ?string $name): array
    {
        return ['type' => 'passkey', 'id' => $id, 'name' => $name];
    }

    private function ttl(): int
    {
        return max(1, (int) config('restv2.challenge_ttl'));
    }
}
