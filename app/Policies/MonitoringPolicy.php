<?php

namespace App\Policies;

use App\Auth\Principal;
use App\Auth\Role;

/**
 * Who may use the monitoring and operations feature (feature 011 FR-001, FR-002, research R26):
 * administrators only, signed in or through an administrator's key; read-only keys are refused on
 * unsafe methods by 001's key scope check before this gate. Registered as the gate
 * `operate-monitoring`.
 */
class MonitoringPolicy
{
    public function operate(Principal $principal): bool
    {
        return $principal->role === Role::Admin;
    }
}
