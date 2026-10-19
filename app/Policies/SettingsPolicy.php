<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;

/**
 * Who may use the system settings of feature 013 (FR-001, FR-005, research R25): administrators
 * only, signed in or through an administrator's key (read-only keys are refused on unsafe methods
 * by 001's key scope check before this gate). Domain administrators reach only the quota
 * notification copy recipients of their domains (QuotaNotificationRecipientsPolicy) and the sieve
 * presets (PresetPolicy). Checked before any lookup, so a refusal never reveals data.
 *
 * Demo mode (FR-012, research R6): mailcow.conf's `DEMO_MODE=y` refuses the customisation writes
 * after the role check (an unauthorised caller still gets `forbidden`) and before validation.
 */
class SettingsPolicy
{
    /**
     * @throws ProblemException `403 forbidden` for every role but administrators
     */
    public function requireAdmin(Principal $principal): void
    {
        if ($principal->role !== Role::Admin) {
            throw new ProblemException(ProblemType::Forbidden);
        }
    }

    /**
     * @throws ProblemException `403 forbidden`, or `403 demo-mode` in demo mode
     */
    public function requireCustomisation(Principal $principal): void
    {
        $this->requireAdmin($principal);
        self::refuseInDemoMode();
    }

    /**
     * @throws ProblemException `403 demo-mode`
     */
    public static function refuseInDemoMode(): void
    {
        if (config('restv2.demo_mode') === true) {
            throw new ProblemException(ProblemType::DemoMode);
        }
    }
}
