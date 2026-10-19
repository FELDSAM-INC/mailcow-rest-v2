<?php

namespace App\Services\Auth;

use App\Audit\AuditEventType;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Exceptions\ProblemException;
use App\Rules\AccountUsername;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single sign-on tokens of domain administrators (feature 007 FR-057 to FR-063, research R20), kept
 * in mailcow's own `da_sso` store, so that a token works once in either the legacy domain
 * administrator login (`/domainadmin?sso_token=`) or this API, and tokens issued through `/api/v1`
 * work here.
 *
 *  - Issue: five groups of six upper-case hexadecimal characters from random_bytes(), as legacy
 *    `domain_admin_sso('issue')`; expired tokens are removed; an inactive account gets none.
 *  - Sign-in: a token is valid when it exists, is younger than 30 seconds and belongs to an active
 *    domain administrator created no later than the token (FR-036). It is consumed with legacy's
 *    `DELETE … WHERE token` and its row count, so that of two concurrent consumers exactly one wins.
 *    No second factor is asked and `tfa` is not re-activated, as in legacy; forced actions restrict
 *    the session (feature 001). Failures are generic, audited and throttled per address, never
 *    reported to fail2ban. The token never reaches a log or audit record.
 */
class SsoTokenService
{
    public const PATTERN = '/^[0-9A-F]{6}(-[0-9A-F]{6}){4}$/';

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SignInThrottle $throttle,
        private readonly SignInAudit $signInAudit,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issues a token for an existing domain administrator.
     *
     * @return array{token: string, username: string, expiresAt: string}
     *
     * @throws NotFoundHttpException|ProblemException account-inactive
     */
    public function issue(Principal $actor, string $username): array
    {
        return DB::transaction(function () use ($actor, $username): array {
            $account = DB::table('admin')->where('username', AccountUsername::normalize($username))->where('superadmin', 0)->lockForUpdate()->first(['username', 'active']);

            if (! $account instanceof stdClass) {
                throw new NotFoundHttpException('The domain administrator does not exist.');
            }

            if ((int) $account->active !== 1) {
                throw new ProblemException(ProblemType::AccountInactive, 'The domain administrator is inactive; no token was issued.');
            }

            $token = implode('-', array_map(fn (): string => strtoupper(bin2hex(random_bytes(3))), range(1, 5)));
            DB::table('da_sso')->insert(['username' => (string) $account->username, 'token' => $token]);
            DB::table('da_sso')->whereRaw('`created` < DATE_SUB(NOW(), INTERVAL ? SECOND)', [$this->lifetime()])->delete();
            $created = (string) DB::table('da_sso')->where('token', $token)->value('created');
            $expiresAt = CarbonImmutable::parse((string) MailcowDateTime::toUtcString($created))->addSeconds($this->lifetime())->format('Y-m-d\TH:i:s\Z');

            $this->audit->record(AuditEventType::SsoTokenIssue, true, $actor, ['type' => 'ssoToken', 'id' => null, 'name' => (string) $account->username], null, null, [
                'expiresAt' => $expiresAt,
            ]);

            return ['token' => $token, 'username' => (string) $account->username, 'expiresAt' => $expiresAt];
        }, 3);
    }

    /**
     * Consumes a valid token and returns its account (`username`, `created`), or null.
     *
     * @return array{username: string, created: string}|null
     */
    public function consume(string $token): ?array
    {
        $token = strtoupper(trim($token));

        if (preg_match(self::PATTERN, $token) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($token): ?array {
            $row = DB::table('da_sso as t')
                ->join('admin as a', 'a.username', '=', 't.username')
                ->where('t.token', $token)
                ->whereRaw('`t`.`created` > DATE_SUB(NOW(), INTERVAL ? SECOND)', [$this->lifetime()])
                ->where('a.active', 1)
                ->where('a.superadmin', 0)
                ->whereColumn('a.created', '<=', 't.created')
                ->lockForUpdate()
                ->first(['a.username', 'a.created']);

            if (! $row instanceof stdClass || DB::table('da_sso')->where('token', $token)->delete() < 1) {
                return null;
            }

            return ['username' => (string) $row->username, 'created' => (string) $row->created];
        });
    }

    /**
     * Signs the request's browser in with a token, replacing any session it holds (FR-063).
     *
     * @throws ProblemException sign-in-failed, too-many-requests
     */
    public function signIn(Request $request, string $token): void
    {
        $ip = (string) $request->ip();
        $key = $this->throttle->ssoTokenKey($ip);
        $details = ['requestedRole' => Role::DomainAdmin->value, 'step' => 'ssoToken'];

        try {
            $this->throttle->assertNotThrottled($key);
        } catch (ProblemException $e) {
            $this->signInAudit->failure(null, Role::DomainAdmin, AuthMethod::SsoToken, 'throttled', $details);

            throw $e;
        }

        $account = $this->consume($token);

        if ($account === null) {
            $this->throttle->delay($this->throttle->hit($key));
            $this->signInAudit->failure($this->tokenOwner($token), Role::DomainAdmin, AuthMethod::SsoToken, 'invalidCredentials', $details);

            throw new ProblemException(ProblemType::SignInFailed);
        }

        $principal = new Principal(
            username: mb_strtolower($account['username']),
            role: Role::DomainAdmin,
            accountKind: Role::DomainAdmin->accountKind(),
            authMethod: AuthMethod::SsoToken,
            signedInAt: CarbonImmutable::now('UTC'),
            accountCreatedAt: $account['created'],
        );

        DB::transaction(fn () => $this->signInAudit->success($principal, [
            'requestedRole' => Role::DomainAdmin->value,
            'restricted' => $this->pendingActions->evaluate($principal) !== [],
        ]));

        $this->throttle->clear($key);
        $this->sessions->promote($request->session(), $principal, $request->userAgent());
    }

    /**
     * The account a failed token was issued for, when the token is still stored (for the audit
     * record; never the token).
     */
    private function tokenOwner(string $token): ?string
    {
        $token = strtoupper(trim($token));

        if (preg_match(self::PATTERN, $token) !== 1) {
            return null;
        }

        $owner = DB::table('da_sso')->where('token', $token)->value('username');

        return is_string($owner) ? $owner : null;
    }

    private function lifetime(): int
    {
        return max(1, (int) config('restv2.sso_token_lifetime', 30));
    }
}
