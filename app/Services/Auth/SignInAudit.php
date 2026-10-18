<?php

namespace App\Services\Auth;

use App\Audit\AuditEventType;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Audit records of sign-in steps (FR-045, FR-046, R22), shared by the password, second-factor
 * and passkey sign-ins. A success is recorded inside the caller's transaction; a failure in its
 * own transaction, so that it is never lost with the failure.
 */
class SignInAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $details  `requestedRole`, `secondFactorType`, `restricted`
     */
    public function success(Principal $principal, array $details): void
    {
        $this->audit->record(
            AuditEventType::SignIn,
            true,
            $principal,
            ['type' => 'session', 'id' => null, 'name' => null],
            $principal->authMethod->value,
            null,
            $details,
        );
    }

    /**
     * @param  string  $reason  an `AuditFailureReason`
     * @param  array<string, mixed>  $details  `requestedRole`, `secondFactorType`, `step`
     */
    public function failure(?string $username, ?Role $role, AuthMethod $method, string $reason, array $details): void
    {
        DB::transaction(fn () => $this->audit->record(
            AuditEventType::SignIn,
            false,
            ['username' => $username, 'role' => $role],
            null,
            $method->value,
            $reason,
            $details,
        ));
    }
}
