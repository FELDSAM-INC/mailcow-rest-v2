<?php

namespace App\Services\SecondFactors;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Account\AccountCapabilities;
use App\Services\Account\CurrentPasswordCheck;
use App\Services\Audit\AuditLogger;
use App\Support\Database\NamedLock;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Removing the signed-in account's own second factors (feature 005 FR-034, FR-035, research R10),
 * ports of legacy unset_tfa_key() and set_tfa('none') with the spec's rules:
 *
 *  - an account an administrator requires to keep a second factor (`force_tfa` 1 or "1"; legacy's
 *    comparison never matches the string) is refused with `second-factor-required-by-policy`;
 *  - one entry is removed without the password, unless it is the account's only active entry
 *    (`last-second-factor`; legacy refuses any removal while exactly one entry is active);
 *  - all entries are removed with the current password; passkeys (`fido2`) are untouched.
 *
 * The check and the delete run under the account's named lock (shared with the enrolments), so two
 * concurrent removals cannot remove the last two active entries.
 */
class SecondFactorRemovalService
{
    public function __construct(
        private readonly AccountCapabilities $accounts,
        private readonly CurrentPasswordCheck $currentPassword,
        private readonly NamedLock $locks,
        private readonly AuditLogger $audit,
    ) {}

    public function removeOne(Principal $principal, int $id): void
    {
        if (! $this->accounts->managedHere($principal)) {
            throw new ProblemException(ProblemType::PasswordManagedExternally);
        }

        $this->locks->run([SecondFactorEnrolmentService::lockName($principal->username)], fn () => DB::transaction(function () use ($principal, $id): void {
            $row = DB::table('tfa')->where('username', $principal->username)->where('id', $id)->first(['id', 'key_id', 'authmech', 'active']);

            if ($row === null) {
                throw new NotFoundHttpException('The second factor does not exist.');
            }

            $details = ['secondFactorId' => $id];

            if ($this->accounts->secondFactorRequired($principal)) {
                throw new ProblemException(ProblemType::SecondFactorRequiredByPolicy, null, [], [], $details);
            }

            if ((int) $row->active === 1 && DB::table('tfa')->where('username', $principal->username)->where('active', 1)->count() === 1) {
                throw new ProblemException(ProblemType::LastSecondFactor, null, [], [], $details);
            }

            DB::table('tfa')->where('username', $principal->username)->where('id', $id)->delete();

            $this->audit->record(
                AuditEventType::OwnSecondFactorRemoval,
                true,
                $principal,
                ['type' => 'secondFactor', 'id' => $principal->username, 'name' => (string) $row->key_id],
                $principal->authMethod->value,
                null,
                ['secondFactorId' => $id, 'secondFactorType' => SecondFactorQueryService::TYPES[(string) $row->authmech] ?? (string) $row->authmech],
            );
        }));
    }

    public function removeAll(Request $request, Principal $principal, string $currentPassword): void
    {
        if (! $this->accounts->managedHere($principal)) {
            throw new ProblemException(ProblemType::PasswordManagedExternally);
        }

        $this->currentPassword->verify($principal, $currentPassword, (string) $request->ip());

        if ($this->accounts->secondFactorRequired($principal)) {
            throw new ProblemException(ProblemType::SecondFactorRequiredByPolicy);
        }

        $this->locks->run([SecondFactorEnrolmentService::lockName($principal->username)], fn () => DB::transaction(function () use ($principal): void {
            $ids = DB::table('tfa')->where('username', $principal->username)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            DB::table('tfa')->where('username', $principal->username)->delete();

            $this->audit->record(
                AuditEventType::OwnSecondFactorRemoval,
                true,
                $principal,
                ['type' => 'secondFactor', 'id' => $principal->username, 'name' => null],
                $principal->authMethod->value,
                null,
                ['removedIds' => $ids],
            );
        }));
    }
}
