<?php

namespace App\Services\Auth;

/**
 * Something tied to a session of this API that must end with it, notified by SessionManager and
 * SessionRegistry (feature 005 research R2, R3): the webmail hand-off of the session. Listeners are
 * tagged `restv2.session-listeners` in the container and identified by the session's handle.
 */
interface SessionEndListener
{
    /**
     * The session with this handle ended: signed out, invalidated or ended by another session.
     */
    public function sessionEnded(string $handle): void;

    /**
     * The session with this handle served a request: its idle lifetime starts again.
     */
    public function sessionTouched(string $handle, int $lifetimeSeconds): void;
}
