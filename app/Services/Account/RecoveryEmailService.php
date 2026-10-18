<?php

namespace App\Services\Account;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\SelfService\PermissionGate;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The signed-in mailbox user's recovery address (feature 005 FR-018, research R20), stored where
 * legacy edit_user_account() stores it (`mailbox.attributes.recovery_email`, `''` when removed), so
 * that both UIs and the reset use it. Mailcow-source mailbox users only; a change needs the current
 * password and the `pwReset` flag unless the address stays the same. Invalid addresses are refused
 * (legacy stores them as empty).
 */
class RecoveryEmailService
{
    public function __construct(
        private readonly AccountCapabilities $accounts,
        private readonly CurrentPasswordCheck $currentPassword,
        private readonly PermissionGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ProblemException forbidden (not a mailbox user) or password-managed-externally
     */
    public function assertEligible(Principal $principal): void
    {
        if ($principal->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users have a recovery address.');
        }

        if (! $this->accounts->managedHere($principal)) {
            throw new ProblemException(ProblemType::PasswordManagedExternally);
        }
    }

    /**
     * @return array{address: string|null, etag: string}
     */
    public function read(Principal $principal): array
    {
        $this->assertEligible($principal);
        $stored = $this->stored($principal->username);

        return ['address' => $stored === '' ? null : $stored, 'etag' => self::etag($stored)];
    }

    /**
     * @return array{address: string|null, etag: string}
     */
    public function replace(Request $request, Principal $principal, string $address, string $currentPassword): array
    {
        $this->assertEligible($principal);
        $old = $this->stored($principal->username);

        if (ETag::ifMatchFails($request, self::etag($old))) {
            throw new PreconditionFailedHttpException('The recovery address has changed.');
        }

        $this->currentPassword->verify($principal, $currentPassword, (string) $request->ip());
        $this->gate->requireChange($principal, PermissionFlag::PwReset, $address !== $old);

        DB::transaction(function () use ($principal, $address, $old): void {
            if ($address !== $old) {
                DB::update(
                    "UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.recovery_email', ?) WHERE `username` = ? AND `authsource` = 'mailcow'",
                    [$address, $principal->username],
                );
            }

            $this->audit->record(
                AuditEventType::OwnRecoveryEmailChange,
                true,
                $principal,
                ['type' => 'recoveryEmail', 'id' => $principal->username, 'name' => null],
                $principal->authMethod->value,
                null,
                ['changes' => $address === $old ? [] : ['recoveryEmail' => ['old' => $old, 'new' => $address]]],
            );
        });

        return $this->read($principal);
    }

    public static function etag(string $stored): string
    {
        return ETag::of(['recoveryEmail', $stored]);
    }

    private function stored(string $username): string
    {
        $raw = DB::table('mailbox')->where('username', $username)->value('attributes');
        $attributes = is_string($raw) ? json_decode($raw, true) : null;
        $value = is_array($attributes) ? ($attributes['recovery_email'] ?? '') : '';

        return is_string($value) ? $value : '';
    }
}
