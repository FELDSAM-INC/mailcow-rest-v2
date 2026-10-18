<?php

namespace App\Services\SecondFactors;

use App\Audit\AuditEventType;
use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use App\Exceptions\ProblemException;
use App\Services\Account\AccountCapabilities;
use App\Services\Account\CurrentPasswordCheck;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PendingActionEvaluator;
use App\Services\Auth\SecondFactorService;
use App\Services\Auth\SessionManager;
use App\Services\Config\MailcowConfig;
use App\Support\Database\NamedLock;
use App\Support\Http\Base64Url;
use App\Support\Http\ProblemType;
use App\Support\Totp\NoQrCodeProvider;
use App\Support\Totp\QrCodeProvider;
use App\Support\WebAuthn\OptionsJson;
use App\Support\WebAuthn\WebAuthnServerFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RobThree\Auth\Algorithm;
use RobThree\Auth\TwoFactorAuth;
use Throwable;

/**
 * Enrolling the signed-in account's own second factors (feature 005 FR-016, FR-029–FR-033,
 * FR-036, research R7–R9), stored in `tfa` exactly as legacy set_tfa() stores them, active at once,
 * so that both UIs verify them:
 *
 *  - TOTP in two steps: the start keeps a new 160-bit secret server-side in the session
 *    (`restv2.totpEnrolment`, 10 minutes, five attempts; legacy sends it through the browser); the
 *    confirmation checks a code with legacy's parameters and marks it used for 001's replay check;
 *  - WebAuthn in two steps: options bound to the session (`restv2.secondFactorWebauthn`,
 *    single-use, 5 minutes) that exclude the account's security keys, then the attestation;
 *  - Yubico OTP: verified with the given API credentials, replacing an entry of the same key.
 *
 * Only administrators, domain administrators and mailcow-source mailboxes manage second factors.
 * The current password is required unless a forced second-factor setup is pending. Inserts run
 * under the account's named lock `restv2_tfa_<sha1(username)>`, which the removals share.
 * Refusals are audited by the route (RefusalAuditor); the starts are ceremony requests and are not.
 */
class SecondFactorEnrolmentService
{
    public const TOTP_SESSION_KEY = 'restv2.totpEnrolment';

    public const WEBAUTHN_SESSION_KEY = 'restv2.secondFactorWebauthn';

    public function __construct(
        private readonly AccountCapabilities $accounts,
        private readonly CurrentPasswordCheck $currentPassword,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly SessionManager $sessions,
        private readonly SecondFactorService $secondFactors,
        private readonly WebAuthnServerFactory $webauthn,
        private readonly YubicoOtpVerifier $yubico,
        private readonly MailcowConfig $config,
        private readonly NamedLock $locks,
        private readonly AuditLogger $audit,
        private readonly SecondFactorQueryService $query,
    ) {}

    /**
     * The account's named lock (docs/conventions.md: uniqueness upstream enforces only in code).
     */
    public static function lockName(string $username): string
    {
        return 'restv2_tfa_'.sha1($username);
    }

    /**
     * Refuses an account whose second factors are not managed here.
     */
    public function assertEligible(Principal $principal): void
    {
        if (! $this->accounts->managedHere($principal)) {
            throw new ProblemException(ProblemType::PasswordManagedExternally);
        }
    }

    public function forcedSetupPending(Principal $principal): bool
    {
        return in_array(PendingAction::SecondFactorSetup, $this->pendingActions->evaluate($principal), true);
    }

    /**
     * Starts a TOTP enrolment, replacing an open one.
     *
     * @return array<string, mixed> contract `OwnTotpEnrolment`
     */
    public function startTotp(Request $request, Principal $principal): array
    {
        $this->assertEligible($principal);

        $label = $this->config->otpLabel();
        $totp = new TwoFactorAuth(new QrCodeProvider, $label, 6, 30, Algorithm::Sha1);
        $secret = $totp->createSecret(160);
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds((int) config('restv2.totp_enrolment_ttl'));

        $request->session()->put(self::TOTP_SESSION_KEY, [
            'username' => $principal->username,
            'secret' => $secret,
            'expiresAt' => $expiresAt->getTimestamp(),
            'attemptsLeft' => (int) config('restv2.totp_enrolment_max_attempts'),
        ]);

        return [
            'secret' => $secret,
            'provisioningUri' => $totp->getQRText($principal->username, $secret),
            'qrCode' => $totp->getQRCodeImageAsDataUri($principal->username, $secret),
            'issuer' => $label,
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'SHA1',
            'expiresAt' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Issues WebAuthn registration options for a security key, replacing earlier ones.
     *
     * @return array{publicKey: array<string, mixed>, expiresAt: CarbonImmutable}
     */
    public function webauthnOptions(Request $request, Principal $principal): array
    {
        $this->assertEligible($principal);

        $excluded = DB::table('tfa')->where('username', $principal->username)->where('authmech', 'webauthn')->orderBy('id')->pluck('keyHandle')
            ->map(fn (mixed $handle): string|false => base64_decode((string) $handle, true))
            ->filter(fn (string|false $id): bool => $id !== false && $id !== '')
            ->values()->all();

        $server = $this->webauthn->make($request);
        $username = $principal->username;
        $args = $server->getCreateArgs($username, $username, $username, max(1, (int) config('restv2.challenge_ttl')), false, $this->config->webauthnUvFlagRegister(), null, $excluded);
        $expiresAt = $this->sessions->issue($request->session(), self::WEBAUTHN_SESSION_KEY, $server->getChallenge()->getBinaryString(), ['username' => $username]);

        return ['publicKey' => OptionsJson::secondFactorCreation($args), 'expiresAt' => $expiresAt];
    }

    /**
     * Enrols a second factor and returns its contract representation.
     *
     * @param  array<string, mixed>  $input  a validated `OwnSecondFactorEnrolmentRequest`
     * @return array<string, mixed>
     */
    public function enrol(Request $request, Principal $principal, array $input): array
    {
        $this->assertEligible($principal);
        $forced = $this->forcedSetupPending($principal);

        if (! $forced) {
            $this->currentPassword->verify($principal, (string) ($input['currentPassword'] ?? ''), (string) $request->ip());
        }

        $name = trim((string) $input['name']);

        $stored = match ((string) $input['type']) {
            'totp' => $this->confirmTotp($request, $principal, $name, (string) $input['code']),
            'webauthn' => $this->completeWebAuthn($request, $principal, $name, (array) $input['credential']),
            default => $this->enrolYubiOtp($principal, $name, (string) $input['clientId'], (string) $input['secretKey'], (string) $input['otp']),
        };

        DB::transaction(fn () => $this->audit->record(
            AuditEventType::OwnSecondFactorEnrolment,
            true,
            $principal,
            ['type' => 'secondFactor', 'id' => $principal->username, 'name' => $name],
            $principal->authMethod->value,
            null,
            array_filter([
                'secondFactorId' => $stored['id'],
                'secondFactorType' => (string) $input['type'],
                'replacedIds' => $stored['replacedIds'] ?? null,
                'restrictionLifted' => $forced && ! $this->forcedSetupPending($principal),
            ], fn (mixed $value): bool => $value !== null),
        ));

        return $this->query->find($principal, $stored['id'], $this->sessions->secondFactorId($request->session()))
            ?? throw new ProblemException(ProblemType::SecondFactorEnrolmentFailed);
    }

    /**
     * @return array{id: int}
     */
    private function confirmTotp(Request $request, Principal $principal, string $name, string $code): array
    {
        $session = $request->session();
        $enrolment = $session->get(self::TOTP_SESSION_KEY);

        if (! is_array($enrolment) || ($enrolment['username'] ?? null) !== $principal->username
            || (int) ($enrolment['expiresAt'] ?? 0) <= CarbonImmutable::now()->getTimestamp()
            || (int) ($enrolment['attemptsLeft'] ?? 0) <= 0) {
            $session->forget(self::TOTP_SESSION_KEY);

            throw new ProblemException(ProblemType::SecondFactorEnrolmentExpired, null, [], [], ['secondFactorType' => 'totp']);
        }

        $secret = (string) $enrolment['secret'];
        $timeslice = 0;
        $totp = new TwoFactorAuth(new NoQrCodeProvider, $this->config->otpLabel(), 6, 30, Algorithm::Sha1);

        if (! $totp->verifyCode($secret, $code, 1, CarbonImmutable::now()->getTimestamp(), $timeslice)) {
            $left = (int) $enrolment['attemptsLeft'] - 1;
            $left > 0 ? $session->put(self::TOTP_SESSION_KEY.'.attemptsLeft', $left) : $session->forget(self::TOTP_SESSION_KEY);

            throw new ProblemException(
                ProblemType::SecondFactorEnrolmentFailed,
                'The code does not match the enrolment\'s secret.',
                ['attemptsRemaining' => $left],
                [],
                ['secondFactorType' => 'totp', 'attemptsRemaining' => $left],
            );
        }

        $id = $this->locks->run([self::lockName($principal->username)], fn (): int => (int) DB::table('tfa')->insertGetId([
            'username' => $principal->username,
            'key_id' => $name,
            'authmech' => 'totp',
            'secret' => $secret,
            'active' => 1,
        ]));

        $session->forget(self::TOTP_SESSION_KEY);
        $this->secondFactors->markTotpCodeUsed($id, (int) $timeslice);

        return ['id' => $id];
    }

    /**
     * @param  array<string, mixed>  $credential  a validated `WebAuthnRegistrationResponse`
     * @return array{id: int}
     */
    private function completeWebAuthn(Request $request, Principal $principal, string $name, array $credential): array
    {
        $options = $this->sessions->consume($request->session(), self::WEBAUTHN_SESSION_KEY);

        if ($options === null || ($options['username'] ?? null) !== $principal->username) {
            throw new ProblemException(ProblemType::SecondFactorEnrolmentExpired, null, [], [], ['secondFactorType' => 'webauthn']);
        }

        $response = (array) ($credential['response'] ?? []);
        $uv = $this->config->webauthnUvFlagRegister();

        try {
            $data = $this->webauthn->make($request)->processCreate(
                Base64Url::decode((string) ($response['clientDataJSON'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['attestationObject'] ?? '')) ?? '',
                $options['challenge'],
                $uv === true || $uv === 'required',
                $this->config->webauthnUserPresent(),
            );
        } catch (Throwable) {
            throw new ProblemException(ProblemType::SecondFactorEnrolmentFailed, 'The authenticator response was rejected.', [], [], ['secondFactorType' => 'webauthn']);
        }

        $id = $this->locks->run([self::lockName($principal->username)], fn (): int => (int) DB::table('tfa')->insertGetId([
            'username' => $principal->username,
            'key_id' => $name,
            'authmech' => 'webauthn',
            'keyHandle' => base64_encode((string) $data->credentialId),
            'publicKey' => (string) $data->credentialPublicKey,
            'certificate' => $data->certificate,
            'counter' => 0,
            'active' => 1,
        ]));

        return ['id' => $id];
    }

    /**
     * @return array{id: int, replacedIds: list<int>}
     */
    private function enrolYubiOtp(Principal $principal, string $name, string $clientId, string $secretKey, string $otp): array
    {
        $result = $this->yubico->verify($clientId, $secretKey, $otp);

        if ($result === YubicoVerificationResult::TemporaryFailure) {
            throw new ProblemException(ProblemType::TemporaryVerificationFailure, null, [], ['Retry-After' => '30'], ['secondFactorType' => 'yubiOtp']);
        }

        if ($result !== YubicoVerificationResult::Valid) {
            throw new ProblemException(ProblemType::SecondFactorEnrolmentFailed, 'The Yubico service rejected the OTP.', [], [], ['secondFactorType' => 'yubiOtp']);
        }

        $modhex = substr($otp, 0, 12);

        return $this->locks->run([self::lockName($principal->username)], fn (): array => DB::transaction(function () use ($principal, $name, $clientId, $secretKey, $modhex): array {
            $pattern = '%'.addcslashes($modhex, '%_\\');
            $replaced = DB::table('tfa')->where('username', $principal->username)->where('authmech', 'yubi_otp')->where('secret', 'like', $pattern)
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            DB::table('tfa')->whereIn('id', $replaced)->delete();

            $id = (int) DB::table('tfa')->insertGetId([
                'key_id' => $name,
                'username' => $principal->username,
                'authmech' => 'yubi_otp',
                'active' => 1,
                'secret' => $clientId.':'.$secretKey.':'.$modhex,
            ]);

            return ['id' => $id, 'replacedIds' => $replaced];
        }));
    }
}
