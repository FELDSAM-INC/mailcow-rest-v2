<?php

namespace App\Services\Account;

use App\Auth\AccountKind;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\Mailbox;
use App\Services\Auth\SignInThrottle;
use App\Support\Hashing\LegacyPasswordVerifier;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * The re-authentication of feature 005 (FR-008, FR-012, FR-029, research R4): the signed-in
 * account's current password, verified against its stored hash in every format feature 001 accepts
 * (administrators and domain administrators in `admin`, mailboxes in `mailbox` with the mailcow
 * source and not a resource, as legacy edit_user_account() and set_tfa() look them up).
 *
 * A wrong password counts on feature 001's sign-in throttle of the account and client address
 * (`restv2:signin:<sha256(username|ip)>`), is delayed like a failed sign-in and answers
 * `403 current-password-incorrect`, which the route's refusal auditing records; it is never
 * reported to fail2ban. A throttled account answers `429` before the password is checked, and a
 * correct password resets the count.
 */
class CurrentPasswordCheck
{
    public function __construct(
        private readonly SignInThrottle $throttle,
        private readonly LegacyPasswordVerifier $verifier,
    ) {}

    /**
     * @throws ProblemException too-many-requests or current-password-incorrect
     */
    public function verify(Principal $principal, string $password, string $ip): void
    {
        $key = $this->throttle->passwordKey($principal->username, $ip);
        $this->throttle->assertNotThrottled($key);

        $hash = $this->storedHash($principal);

        if ($hash === null || ! $this->verifier->verify($hash, $password)) {
            $this->throttle->delay($this->throttle->hit($key));

            throw new ProblemException(ProblemType::CurrentPasswordIncorrect);
        }

        $this->throttle->clear($key);
    }

    private function storedHash(Principal $principal): ?string
    {
        $hash = $principal->accountKind === AccountKind::Admin
            ? DB::table('admin')->where('username', $principal->username)->value('password')
            : DB::table('mailbox')
                ->where('username', $principal->username)
                ->where('authsource', 'mailcow')
                ->whereRaw('`kind` NOT REGEXP ?', [Mailbox::RESOURCE_KIND_PATTERN])
                ->value('password');

        return is_string($hash) ? $hash : null;
    }
}
